<?php

declare(strict_types=1);

namespace Tests\Feature\HealthcareService;

use App\Enums\HealthcareService\Status;
use App\Enums\JobStatus;
use App\Enums\User\Role;
use App\Jobs\HealthcareServiceSync;
use App\Livewire\Division\HealthcareService\HealthcareServiceIndex;
use App\Models\Division;
use App\Models\HealthcareService;
use App\Models\LegalEntity;
use Illuminate\Bus\PendingBatch;
use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Crypt;
use Livewire\Livewire;
use Tests\Concerns\MocksBasicDictionaries;
use Tests\Concerns\RefreshInstalledDatabase;
use Tests\TestCase;

/**
 * Synchronizing the healthcare services of the legal entity from the list page: the services eHealth returns are
 * stored as eHealth returns them.
 *
 * Only eHealth is faked, answering with a page of the list. The tests go in through the page and check the database,
 * so they don't depend on the classes that map the response to the database and fail only when what is stored changes.
 * The queue is faked only where the list has more pages, to check which filter the next pages are requested with.
 */
class HealthcareServiceSyncTest extends TestCase
{
    use MocksBasicDictionaries;
    use PreparesHealthcareServiceTests;
    use RefreshInstalledDatabase;
    use WithFaker;

    /**
     * An MSP service is stored with every field eHealth returned: its speciality and providing condition, its
     * comment, its schedules, and who and when registered and last changed it in eHealth. The weekly schedule is kept
     * as eHealth gave it, while the not available periods and the registration time are read in local time, so a
     * service registered late in the UTC day has the next local date. The schedules are compared regardless of the
     * order of keys, since the jsonb columns keep the keys in an order of their own.
     *
     * @return void
     */
    public function test_msp_service_is_stored_as_ehealth_returns_it(): void
    {
        $legalEntity = LegalEntity::factory()->synced()->create();
        $division = Division::factory()->for($legalEntity)->create();
        $uuid = $this->faker->uuid();
        $comment = $this->faker->sentence();
        $insertedBy = $this->faker->uuid();
        $updatedBy = $this->faker->uuid();
        $availableTime = [
            ['days_of_week' => ['mon', 'tue'], 'all_day' => false, 'available_start_time' => '08:00:00', 'available_end_time' => '17:00:00'],
            ['days_of_week' => ['sat'], 'all_day' => true]
        ];
        $description = $this->faker->sentence();
        $ehealthNotAvailable = [
            ['description' => $description, 'during' => ['start' => '2026-12-01T07:00:00Z', 'end' => '2026-12-01T11:00:00Z']]
        ];
        $notAvailable = [
            [
                'description' => $description,
                'during' => ['startDate' => '01.12.2026', 'startTime' => '09:00', 'endDate' => '01.12.2026', 'endTime' => '13:00']
            ]
        ];

        $eHealth = HealthcareServiceApiFake::respond(200, $this->ehealthList([
            $this->ehealthHealthcareService($legalEntity, $division, [
                'id' => $uuid,
                'category' => ['coding' => [['system' => 'HEALTHCARE_SERVICE_CATEGORIES', 'code' => 'MSP']]],
                'speciality_type' => 'THERAPIST',
                'providing_condition' => 'OUTPATIENT',
                'comment' => $comment,
                'available_time' => $availableTime,
                'not_available' => $ehealthNotAvailable,
                'status' => Status::ACTIVE->value,
                'is_active' => true,
                'inserted_at' => '2026-10-30T22:30:00Z',
                'inserted_by' => $insertedBy,
                'updated_at' => '2026-11-02T10:00:00Z',
                'updated_by' => $updatedBy
            ])
        ]));

        $listPage = Livewire::actingAs($this->userWithRole($legalEntity, Role::OWNER))
            ->test(HealthcareServiceIndex::class, ['legalEntity' => $legalEntity]);
        $this->travel(1)->seconds();

        $listPage->call('sync')
            ->assertSee(__('healthcare-services.success.sync_updated'));

        $this->assertCount(1, $eHealth->receivedRequests);
        $this->assertSame('GET', $eHealth->receivedRequests[0]->method());

        $healthcareService = HealthcareService::whereUuid($uuid)->sole();
        $this->assertSame($division->id, $healthcareService->divisionId);
        $this->assertSame($legalEntity->id, $healthcareService->legalEntityId);
        $this->assertSame('MSP', $healthcareService->category->coding->first()->code);
        $this->assertNull($healthcareService->type);
        $this->assertSame('THERAPIST', $healthcareService->specialityType);
        $this->assertSame('OUTPATIENT', $healthcareService->providingCondition);
        $this->assertNull($healthcareService->licenseId);
        $this->assertSame($comment, $healthcareService->comment);
        $this->assertEquals($availableTime, $healthcareService->availableTime);
        $this->assertEquals($notAvailable, $healthcareService->notAvailable);
        $this->assertSame(Status::ACTIVE, $healthcareService->status);
        $this->assertTrue($healthcareService->isActive);
        $this->assertSame('2026-10-31 00:30:00', $healthcareService->ehealthInsertedAt->toDateTimeString());
        $this->assertSame($insertedBy, $healthcareService->ehealthInsertedBy);
        $this->assertSame('2026-11-02 12:00:00', $healthcareService->ehealthUpdatedAt->toDateTimeString());
        $this->assertSame($updatedBy, $healthcareService->ehealthUpdatedBy);
    }

    /**
     * A pharmacy drugs service is stored with its type and license, and without a speciality or providing condition.
     *
     * @return void
     */
    public function test_pharmacy_service_is_stored_with_its_type_and_license(): void
    {
        $legalEntity = LegalEntity::factory()->ofType(LegalEntity::TYPE_OUTPATIENT)->synced()->create();
        $division = Division::factory()->for($legalEntity)->create();
        $uuid = $this->faker->uuid();
        $licenseId = $this->faker->uuid();

        HealthcareServiceApiFake::respond(200, $this->ehealthList([
            $this->ehealthHealthcareService($legalEntity, $division, [
                'id' => $uuid,
                'category' => ['coding' => [['system' => 'HEALTHCARE_SERVICE_CATEGORIES', 'code' => 'PHARMACY_DRUGS']]],
                'type' => ['coding' => [['system' => 'HEALTHCARE_SERVICE_PHARMACY_DRUGS_TYPES', 'code' => 'SALE']]],
                'speciality_type' => null,
                'providing_condition' => null,
                'license_id' => $licenseId
            ])
        ]));

        Livewire::actingAs($this->userWithRole($legalEntity, Role::OWNER))
            ->test(HealthcareServiceIndex::class, ['legalEntity' => $legalEntity])
            ->call('sync');

        $healthcareService = HealthcareService::whereUuid($uuid)->sole();
        $this->assertSame('PHARMACY_DRUGS', $healthcareService->category->coding->first()->code);
        $this->assertSame('SALE', $healthcareService->type->coding->first()->code);
        $this->assertSame($licenseId, $healthcareService->licenseId);
        $this->assertNull($healthcareService->specialityType);
        $this->assertNull($healthcareService->providingCondition);
    }

    /**
     * A service stored before is updated in place with what eHealth returns now, keeping its category record.
     *
     * @return void
     */
    public function test_stored_service_is_updated_with_what_ehealth_returns(): void
    {
        $legalEntity = LegalEntity::factory()->synced()->create();
        $division = Division::factory()->for($legalEntity)->create();
        $healthcareService = HealthcareService::factory()->for($division)->create(['comment' => $this->faker->sentence()]);
        $categoryId = $healthcareService->categoryId;
        $comment = $this->faker->sentence();

        HealthcareServiceApiFake::respond(200, $this->ehealthList([
            $this->ehealthHealthcareService($legalEntity, $division, [
                'id' => $healthcareService->uuid,
                'comment' => $comment,
                'status' => Status::INACTIVE->value,
                'is_active' => false
            ])
        ]));

        Livewire::actingAs($this->userWithRole($legalEntity, Role::OWNER))
            ->test(HealthcareServiceIndex::class, ['legalEntity' => $legalEntity])
            ->call('sync');

        $this->assertDatabaseCount('healthcare_services', 1);
        $healthcareService->refresh();
        $this->assertSame($comment, $healthcareService->comment);
        $this->assertSame(Status::INACTIVE, $healthcareService->status);
        $this->assertFalse($healthcareService->isActive);
        $this->assertSame($categoryId, $healthcareService->categoryId);
    }

    /**
     * A list that fails validation is not stored, and the user is told the synchronization failed.
     *
     * @return void
     */
    public function test_invalid_ehealth_response_stores_nothing(): void
    {
        $legalEntity = LegalEntity::factory()->synced()->create();
        $division = Division::factory()->for($legalEntity)->create();
        $invalidHealthcareService = $this->ehealthHealthcareService($legalEntity, $division);
        unset($invalidHealthcareService['status']);

        HealthcareServiceApiFake::respond(200, $this->ehealthList([$invalidHealthcareService]));

        $listPage = Livewire::actingAs($this->userWithRole($legalEntity, Role::OWNER))
            ->test(HealthcareServiceIndex::class, ['legalEntity' => $legalEntity]);
        $this->travel(1)->seconds();

        $listPage->call('sync')
            ->assertSee(__('healthcare-services.error.sync_failed'));

        $this->assertDatabaseCount('healthcare_services', 0);
    }

    /**
     * A service of a division that is not synchronized yet waits for the next division synchronization and doesn't
     * prevent the other services of the page from being stored.
     *
     * @return void
     */
    public function test_service_of_unsynchronized_division_does_not_block_the_page(): void
    {
        $legalEntity = LegalEntity::factory()->synced()->create();
        $division = Division::factory()->for($legalEntity)->create();
        $uuid = $this->faker->uuid();

        HealthcareServiceApiFake::respond(200, $this->ehealthList([
            $this->ehealthHealthcareService($legalEntity, $division, ['division_id' => $this->faker->uuid()]),
            $this->ehealthHealthcareService($legalEntity, $division, ['id' => $uuid])
        ]));

        $listPage = Livewire::actingAs($this->userWithRole($legalEntity, Role::OWNER))
            ->test(HealthcareServiceIndex::class, ['legalEntity' => $legalEntity]);
        $this->travel(1)->seconds();

        $listPage->call('sync')
            ->assertSee(__('healthcare-services.success.sync_updated'));

        $this->assertDatabaseCount('healthcare_services', 1);
        $this->assertTrue(HealthcareService::whereUuid($uuid)->exists());
    }

    /**
     * The list filtered by a division is requested with the division, and the jobs of the next pages get the same
     * filter, so they don't continue with the pages of the whole legal entity.
     *
     * @return void
     */
    public function test_next_pages_are_synchronized_with_the_division_filter(): void
    {
        Bus::fake();
        $legalEntity = LegalEntity::factory()->synced()->create(['hcs_sync_status' => JobStatus::COMPLETED->value]);
        $division = Division::factory()->for($legalEntity)->create();

        $eHealth = HealthcareServiceApiFake::respond(200, $this->ehealthList(
            [$this->ehealthHealthcareService($legalEntity, $division)],
            totalPages: 2
        ));

        $user = $this->userWithRole($legalEntity, Role::OWNER);
        session()->put(config('ehealth.api.oauth.bearer_token'), $this->faker->sha256());

        Livewire::actingAs($user)
            ->withQueryParams(['division' => $division->id])
            ->test(HealthcareServiceIndex::class, ['legalEntity' => $legalEntity])
            ->call('sync');

        $this->assertSame($division->uuid, $this->requestQuery($eHealth->receivedRequests[0])['division_id']);
        Bus::assertBatched(
            static fn (PendingBatch $batch): bool => $batch->jobs->first() instanceof HealthcareServiceSync
                && $batch->options['query'] === ['division_id' => $division->uuid]
        );
    }

    /**
     * The job of the next page requests it with the filter of the first page.
     *
     * @return void
     */
    public function test_next_page_job_requests_the_page_with_the_filter_of_the_first_one(): void
    {
        Bus::fake();
        $legalEntity = LegalEntity::factory()->synced()->create();
        $division = Division::factory()->for($legalEntity)->create();

        $eHealth = HealthcareServiceApiFake::respond(200, $this->ehealthList(
            [$this->ehealthHealthcareService($legalEntity, $division)],
            pageNumber: 2,
            totalPages: 2
        ));

        [$job] = new HealthcareServiceSync($legalEntity, page: 2)->withFakeBatch(options: [
            'token' => Crypt::encryptString($this->faker->sha256()),
            'sync_entity' => LegalEntity::ENTITY_HEALTHCARE_SERVICE,
            'query' => ['division_id' => $division->uuid]
        ]);
        $this->expectOutputRegex('/Processing Job/');
        $job->handle();

        $query = $this->requestQuery($eHealth->receivedRequests[0]);
        $this->assertSame('2', $query['page']);
        $this->assertSame($division->uuid, $query['division_id']);
    }

    /**
     * A synchronization that failed is repeated for the whole legal entity even with a division chosen, since the
     * sync ends as completed for the whole legal entity.
     *
     * @return void
     */
    public function test_failed_synchronization_is_repeated_for_the_whole_legal_entity(): void
    {
        $legalEntity = LegalEntity::factory()->synced()->create(['hcs_sync_status' => JobStatus::FAILED->value]);
        $division = Division::factory()->for($legalEntity)->create();

        $eHealth = HealthcareServiceApiFake::respond(200, $this->ehealthList([
            $this->ehealthHealthcareService($legalEntity, $division)
        ]));

        $user = $this->userWithRole($legalEntity, Role::OWNER);
        session()->put(config('ehealth.api.oauth.bearer_token'), $this->faker->sha256());

        Livewire::actingAs($user)
            ->withQueryParams(['division' => $division->id])
            ->test(HealthcareServiceIndex::class, ['legalEntity' => $legalEntity])
            ->call('sync');

        $this->assertArrayNotHasKey('division_id', $this->requestQuery($eHealth->receivedRequests[0]));
        $this->assertSame(JobStatus::COMPLETED->value, $legalEntity->refresh()->getEntityStatus(LegalEntity::ENTITY_HEALTHCARE_SERVICE));
    }

    /**
     * Cache the dictionaries the page uses.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->mockBasicDictionaries();
    }

    /**
     * A page of the healthcare service list, the only one unless told otherwise, as the eHealth "get healthcare
     * services" endpoint returns it.
     *
     * @param  array  $healthcareServices
     * @param  int  $pageNumber
     * @param  int  $totalPages
     * @return array
     */
    private function ehealthList(array $healthcareServices, int $pageNumber = 1, int $totalPages = 1): array
    {
        return [
            'data' => $healthcareServices,
            'paging' => [
                'page_number' => $pageNumber,
                'page_size' => 50,
                'total_entries' => count($healthcareServices),
                'total_pages' => $totalPages
            ]
        ];
    }

    /**
     * Query parameters the request was sent with.
     *
     * @param  Request  $request
     * @return array
     */
    private function requestQuery(Request $request): array
    {
        parse_str(parse_url($request->url(), PHP_URL_QUERY) ?? '', $query);

        return $query;
    }
}
