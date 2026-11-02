<?php

declare(strict_types=1);

namespace Tests\Feature\HealthcareService;

use App\Enums\HealthcareService\Status;
use App\Enums\User\Role;
use App\Livewire\Division\HealthcareService\HealthcareServiceCreate;
use App\Models\Division;
use App\Models\HealthcareService;
use App\Models\LegalEntity;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Concerns\MocksBasicDictionaries;
use Tests\TestCase;

/**
 * Creating a healthcare service: saving it as a local draft or sending it to eHealth.
 *
 * Only what lies outside the application is faked: eHealth answers through a stubbed HTTP client, and the
 * dictionaries, which the application downloads from eHealth, are put straight into the cache it reads them from.
 * Authorization, validation and the database stay real.
 */
class HealthcareServiceCreateTest extends TestCase
{
    use RefreshDatabase;
    use WithFaker;
    use MocksBasicDictionaries;

    /**
     * Legal entity the user works in.
     *
     * @var LegalEntity
     */
    private LegalEntity $legalEntity;

    /**
     * Division the service is created for.
     *
     * @var Division
     */
    private Division $division;

    /**
     * Owner of the legal entity, allowed to create healthcare services.
     *
     * @var User
     */
    private User $owner;

    /**
     * A filled form is sent to eHealth, and the service eHealth returns is stored for the division.
     *
     * @return void
     */
    public function test_service_is_sent_to_ehealth_and_stored_as_returned(): void
    {
        $uuid = $this->faker->uuid();
        $eHealth = HealthcareServiceApiFake::respond(201, ['data' => $this->ehealthHealthcareService(['id' => $uuid])]);

        $this->filledForm()
            ->call('create')
            ->assertHasNoErrors()
            ->assertRedirectToRoute('healthcare-service.index', [$this->legalEntity, $this->division->id]);

        $this->assertCount(1, $eHealth->receivedRequests);
        $request = $eHealth->receivedRequests[0];
        $this->assertSame('POST', $request->method());
        $this->assertStringEndsWith('/api/healthcare_services', $request->url());
        $this->assertSame($this->division->uuid, $request['division_id']);
        $this->assertSame('MSP', $request['category']['coding'][0]['code']);
        $this->assertSame('FAMILY_DOCTOR', $request['speciality_type']);
        $this->assertSame('OUTPATIENT', $request['providing_condition']);

        $healthcareService = HealthcareService::whereUuid($uuid)->firstOrFail();
        $this->assertSame($this->division->id, $healthcareService->divisionId);
        $this->assertSame($this->legalEntity->id, $healthcareService->legalEntityId);
        $this->assertSame(Status::ACTIVE, $healthcareService->status);
        $this->assertTrue($healthcareService->isActive);
        $this->assertSame('FAMILY_DOCTOR', $healthcareService->specialityType);
        $this->assertSame('OUTPATIENT', $healthcareService->providingCondition);
        $this->assertSame('MSP', $healthcareService->category->coding->first()->code);
        $this->assertSame('2026-10-30', $healthcareService->ehealthInsertedAt->toDateString());
        $this->assertSame(__('healthcare-services.success.created'), session('success'));
    }

    /**
     * When eHealth rejects the request, nothing is stored and the user sees the error eHealth returned.
     *
     * @return void
     */
    public function test_failed_ehealth_request_stores_nothing(): void
    {
        HealthcareServiceApiFake::respond(500, ['error' => ['message' => 'Internal server error']]);

        $createPage = $this->filledForm();
        $this->travel(1)->seconds();

        $createPage->call('create')
            ->assertNoRedirect()
            ->assertSee('500: Internal server error');

        $this->assertDatabaseCount('healthcare_services', 0);
    }

    /**
     * When eHealth can't be reached, nothing is stored and the user is told the connection failed.
     *
     * @return void
     */
    public function test_unreachable_ehealth_stores_nothing(): void
    {
        HealthcareServiceApiFake::unreachable();

        $createPage = $this->filledForm();
        $this->travel(1)->seconds();

        $createPage->call('create')
            ->assertNoRedirect()
            ->assertSee(__('messages.connection_exception'));

        $this->assertDatabaseCount('healthcare_services', 0);
    }

    /**
     * A response that fails validation is not stored.
     *
     * @return void
     */
    public function test_invalid_ehealth_response_stores_nothing(): void
    {
        $invalidHealthcareService = $this->ehealthHealthcareService();
        unset($invalidHealthcareService['status']);

        HealthcareServiceApiFake::respond(201, ['data' => $invalidHealthcareService]);

        $createPage = $this->filledForm();
        $this->travel(1)->seconds();

        $createPage->call('create')
            ->assertNoRedirect()
            ->assertSee(__('messages.database_error'));

        $this->assertDatabaseCount('healthcare_services', 0);
    }

    /**
     * A form that fails validation never reaches eHealth.
     *
     * @return void
     */
    public function test_invalid_form_is_not_sent_to_ehealth(): void
    {
        $eHealth = HealthcareServiceApiFake::respond(201, ['data' => $this->ehealthHealthcareService()]);

        Livewire::actingAs($this->owner)
            ->test(HealthcareServiceCreate::class, ['legalEntity' => $this->legalEntity, 'division' => $this->division])
            ->call('create')
            ->assertHasErrors('form.category.coding.0.code')
            ->assertNoRedirect();

        $this->assertSame([], $eHealth->receivedRequests);
        $this->assertDatabaseCount('healthcare_services', 0);
    }

    /**
     * An MSP service without a speciality and a providing condition is not sent to eHealth: the configuration makes
     * both required for the category.
     *
     * @return void
     */
    public function test_service_without_speciality_and_providing_condition_is_not_sent(): void
    {
        $eHealth = HealthcareServiceApiFake::respond(201, ['data' => $this->ehealthHealthcareService()]);

        Livewire::actingAs($this->owner)
            ->test(HealthcareServiceCreate::class, ['legalEntity' => $this->legalEntity, 'division' => $this->division])
            ->set('form.category.coding.0.code', 'MSP')
            ->call('create')
            ->assertHasErrors(['form.specialityType', 'form.providingCondition'])
            ->assertNoRedirect();

        $this->assertSame([], $eHealth->receivedRequests);
        $this->assertDatabaseCount('healthcare_services', 0);
    }

    /**
     * A doctor of the legal entity has no right to create healthcare services and is stopped before anything is sent.
     *
     * @return void
     */
    public function test_user_without_permission_cannot_create_service(): void
    {
        $doctor = User::factory()->create();
        $doctor->assignRole(Role::DOCTOR);
        $this->assertFalse($doctor->can('healthcare_service:write'));

        $eHealth = HealthcareServiceApiFake::respond(201, ['data' => $this->ehealthHealthcareService()]);

        $createPage = $this->filledForm($doctor);
        $this->travel(1)->seconds();

        $createPage->call('create')
            ->assertNoRedirect()
            ->assertSee(__('healthcare-services.policy.create'));

        $this->assertSame([], $eHealth->receivedRequests);
        $this->assertDatabaseCount('healthcare_services', 0);
    }

    /**
     * The schedules and the comment go to eHealth in its format: snake_case keys, no empty times for an all day entry,
     * not available periods in UTC. The service is stored with them, and the periods read back in local time as
     * the user entered them.
     *
     * @return void
     */
    public function test_schedule_and_comment_are_sent_in_ehealth_format_and_stored(): void
    {
        $uuid = $this->faker->uuid();
        $comment = $this->faker->sentence();
        $description = $this->faker->sentence();

        $availableTime = [
            ['daysOfWeek' => ['mon'], 'allDay' => true, 'availableStartTime' => null, 'availableEndTime' => null],
            ['daysOfWeek' => ['tue'], 'allDay' => false, 'availableStartTime' => '09:00:00', 'availableEndTime' => '18:00:00']
        ];
        $periodDate = CarbonImmutable::create(2026, 12, 1)->format(config('app.date_format'));
        $notAvailable = [
            [
                'during' => ['startDate' => $periodDate, 'startTime' => '09:00', 'endDate' => $periodDate, 'endTime' => '13:00'],
                'description' => $description
            ]
        ];

        $ehealthAvailableTime = [
            ['days_of_week' => ['mon'], 'all_day' => true, 'available_start_time' => null, 'available_end_time' => null],
            ['days_of_week' => ['tue'], 'all_day' => false, 'available_start_time' => '09:00:00', 'available_end_time' => '18:00:00']
        ];
        $ehealthNotAvailable = [
            [
                'description' => $description,
                'during' => ['start' => '2026-12-01T07:00:00.000000Z', 'end' => '2026-12-01T11:00:00.000000Z']
            ]
        ];

        $eHealth = HealthcareServiceApiFake::respond(201, [
            'data' => $this->ehealthHealthcareService([
                'id' => $uuid,
                'comment' => $comment,
                'available_time' => $ehealthAvailableTime,
                'not_available' => $ehealthNotAvailable
            ])
        ]);

        $this->filledForm()
            ->set('form.comment', $comment)
            ->set('form.availableTime', $availableTime)
            ->set('form.notAvailable', $notAvailable)
            ->call('create')
            ->assertHasNoErrors();

        $payload = $eHealth->receivedRequests[0]->data();
        $this->assertSame($comment, $payload['comment']);
        $this->assertSame([
            ['days_of_week' => ['mon'], 'all_day' => true],
            ['days_of_week' => ['tue'], 'all_day' => false, 'available_start_time' => '09:00:00', 'available_end_time' => '18:00:00']
        ], $payload['available_time']);
        $this->assertSame([
            ['description' => $description, 'during' => ['start' => '2026-12-01T07:00:00Z', 'end' => '2026-12-01T11:00:00Z']]
        ], $payload['not_available']);

        $healthcareService = HealthcareService::whereUuid($uuid)->firstOrFail();
        $this->assertSame($comment, $healthcareService->comment);
        $this->assertEquals($ehealthAvailableTime, $healthcareService->availableTime);
        $this->assertEquals($notAvailable, $healthcareService->notAvailable);
    }

    /**
     * A not available period that ends before it starts on the same day is shown as an error under its end time and
     * is not sent to eHealth. The form compares date and time together in its own check.
     *
     * @return void
     */
    public function test_not_available_period_ending_before_it_starts_is_not_sent(): void
    {
        $periodDate = CarbonImmutable::create(2026, 12, 1)->format(config('app.date_format'));
        $eHealth = HealthcareServiceApiFake::respond(201, ['data' => $this->ehealthHealthcareService()]);

        $this->filledForm()
            ->set('form.notAvailable', [
                [
                    'during' => ['startDate' => $periodDate, 'startTime' => '13:00', 'endDate' => $periodDate, 'endTime' => '09:00'],
                    'description' => $this->faker->sentence()
                ]
            ])
            ->call('create')
            ->assertHasErrors('form.notAvailable.0.during.endTime')
            ->assertNoRedirect();

        $this->assertSame([], $eHealth->receivedRequests);
        $this->assertDatabaseCount('healthcare_services', 0);
    }

    /**
     * A draft is stored locally with what the user filled in and is not sent to eHealth. eHealth is faked only to
     * prove that no request goes out.
     *
     * @return void
     */
    public function test_draft_is_stored_locally_without_sending_to_ehealth(): void
    {
        $comment = $this->faker->sentence();
        $eHealth = HealthcareServiceApiFake::respond(201, ['data' => $this->ehealthHealthcareService()]);

        $this->filledForm()
            ->set('form.comment', $comment)
            ->call('createLocally')
            ->assertHasNoErrors()
            ->assertRedirectToRoute('healthcare-service.index', [$this->legalEntity, $this->division->id]);

        $this->assertSame([], $eHealth->receivedRequests);

        $healthcareService = HealthcareService::sole();
        $this->assertNull($healthcareService->uuid);
        $this->assertSame(Status::DRAFT, $healthcareService->status);
        $this->assertFalse($healthcareService->isActive);
        $this->assertSame($this->division->id, $healthcareService->divisionId);
        $this->assertSame($this->legalEntity->id, $healthcareService->legalEntityId);
        $this->assertSame('MSP', $healthcareService->category->coding->first()->code);
        $this->assertNull($healthcareService->type);
        $this->assertSame('FAMILY_DOCTOR', $healthcareService->specialityType);
        $this->assertSame('OUTPATIENT', $healthcareService->providingCondition);
        $this->assertSame($comment, $healthcareService->comment);
        $this->assertSame(__('healthcare-services.success.draft_created'), session('success'));
    }

    /**
     * A draft that fails validation is not stored.
     *
     * @return void
     */
    public function test_invalid_draft_is_not_stored(): void
    {
        Livewire::actingAs($this->owner)
            ->test(HealthcareServiceCreate::class, ['legalEntity' => $this->legalEntity, 'division' => $this->division])
            ->call('createLocally')
            ->assertHasErrors('form.category.coding.0.code')
            ->assertNoRedirect();

        $this->assertDatabaseCount('healthcare_services', 0);
    }

    /**
     * A doctor of the legal entity has no right to create healthcare services, drafts included.
     *
     * @return void
     */
    public function test_user_without_permission_cannot_save_draft(): void
    {
        $doctor = User::factory()->create();
        $doctor->assignRole(Role::DOCTOR);
        $this->assertFalse($doctor->can('healthcare_service:write'));

        $createPage = $this->filledForm($doctor);
        $this->travel(1)->seconds();

        $createPage->call('createLocally')
            ->assertNoRedirect()
            ->assertSee(__('healthcare-services.policy.create'));

        $this->assertDatabaseCount('healthcare_services', 0);
    }

    /**
     * Create the legal entity with its division and owner, and cache the dictionaries the page uses.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->legalEntity = LegalEntity::factory()->create();
        $this->division = Division::factory()->for($this->legalEntity)->create();

        $this->app->instance('legalEntity', $this->legalEntity);
        setPermissionsTeamId($this->legalEntity->id);

        $this->owner = User::factory()->create();
        $this->owner->assignRole(Role::OWNER);

        $this->mockBasicDictionaries();
    }

    /**
     * Build the schema the way a fresh installation does: install migrations hold the full current schema, update
     * migrations are only for upgrading existing databases.
     *
     * @return array
     */
    protected function migrateFreshUsing(): array
    {
        return [
            '--path' => database_path('migrations/install'),
            '--realpath' => true
        ];
    }

    /**
     * Create page opened by the given user, the owner by default, with the form filled for a family doctor service
     * in outpatient conditions.
     *
     * @param  User|null  $user
     * @return Testable
     */
    private function filledForm(?User $user = null): Testable
    {
        return Livewire::actingAs($user ?? $this->owner)
            ->test(HealthcareServiceCreate::class, ['legalEntity' => $this->legalEntity, 'division' => $this->division])
            ->set('form.category.coding.0.code', 'MSP')
            ->set('form.specialityType', 'FAMILY_DOCTOR')
            ->set('form.providingCondition', 'OUTPATIENT');
    }

    /**
     * Healthcare service in the shape the eHealth "create healthcare service" endpoint returns it.
     *
     * @param  array  $overrides
     * @return array
     */
    private function ehealthHealthcareService(array $overrides = []): array
    {
        return array_replace([
            'id' => $this->faker->uuid(),
            'division_id' => $this->division->uuid,
            'legal_entity_id' => $this->legalEntity->uuid,
            'category' => ['coding' => [['system' => 'HEALTHCARE_SERVICE_CATEGORIES', 'code' => 'MSP']]],
            'speciality_type' => 'FAMILY_DOCTOR',
            'providing_condition' => 'OUTPATIENT',
            'status' => Status::ACTIVE->value,
            'is_active' => true,
            'available_time' => [],
            'not_available' => [],
            'inserted_at' => '2026-10-30T10:00:00.000000Z',
            'inserted_by' => $this->faker->uuid(),
            'updated_at' => '2026-10-30T10:00:00.000000Z',
            'updated_by' => $this->faker->uuid()
        ], $overrides);
    }
}
