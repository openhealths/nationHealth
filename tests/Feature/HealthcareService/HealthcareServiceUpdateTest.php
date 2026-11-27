<?php

declare(strict_types=1);

namespace Tests\Feature\HealthcareService;

use App\Enums\User\Role;
use App\Livewire\Division\HealthcareService\HealthcareServiceUpdate;
use App\Models\Division;
use App\Models\HealthcareService;
use App\Models\LegalEntity;
use Illuminate\Foundation\Testing\WithFaker;
use Livewire\Livewire;
use Tests\Concerns\MocksBasicDictionaries;
use Tests\Concerns\RefreshInstalledDatabase;
use Tests\TestCase;

/**
 * Updating the comment and the schedules of a healthcare service registered in eHealth, the only fields eHealth
 * allows to change.
 *
 * Only eHealth is faked. The tests go in through the page and check what it shows, what is sent to eHealth and what is
 * stored, so they don't depend on the classes that map the form and the response.
 */
class HealthcareServiceUpdateTest extends TestCase
{
    use MocksBasicDictionaries;
    use PreparesHealthcareServiceTests;
    use RefreshInstalledDatabase;
    use WithFaker;

    /**
     * The page opens with the stored comment and schedules. The not available periods are stored in UTC and shown in
     * local time, as the user entered them.
     *
     * @return void
     */
    public function test_page_shows_stored_schedules_with_periods_in_local_time(): void
    {
        $legalEntity = LegalEntity::factory()->create();
        $division = Division::factory()->for($legalEntity)->create();
        $comment = $this->faker->sentence();
        $description = $this->faker->sentence();
        $healthcareService = HealthcareService::factory()->for($division)->create([
            'comment' => $comment,
            'available_time' => [
                ['days_of_week' => ['mon', 'tue'], 'all_day' => false, 'available_start_time' => '08:00:00', 'available_end_time' => '17:00:00']
            ],
            'not_available' => [
                ['description' => $description, 'during' => ['start' => '2026-12-01T07:00:00Z', 'end' => '2026-12-01T11:00:00Z']]
            ]
        ]);

        $updatePage = Livewire::actingAs($this->userWithRole($legalEntity, Role::OWNER))
            ->test(HealthcareServiceUpdate::class, [
                'legalEntity' => $legalEntity,
                'division' => $division,
                'healthcareService' => $healthcareService
            ]);

        $updatePage->assertSet('form.comment', $comment)
            ->assertSet('form.availableTime', [
                ['daysOfWeek' => ['mon', 'tue'], 'allDay' => false, 'availableStartTime' => '08:00:00', 'availableEndTime' => '17:00:00']
            ])
            ->assertSet('form.notAvailable', [
                [
                    'description' => $description,
                    'during' => ['startDate' => '01.12.2026', 'startTime' => '09:00', 'endDate' => '01.12.2026', 'endTime' => '13:00']
                ]
            ]);
    }

    /**
     * The changed comment and schedules go to eHealth in its format, with the not available periods in UTC, and the
     * service is stored as eHealth returns it. The stored not available periods are read back in local time, as the
     * form shows them. The stored schedules are compared regardless of the order of keys, since the jsonb columns keep
     * the keys in an order of their own.
     *
     * @return void
     */
    public function test_changed_schedules_and_comment_are_sent_to_ehealth_and_stored(): void
    {
        $legalEntity = LegalEntity::factory()->create();
        $division = Division::factory()->for($legalEntity)->create();
        $healthcareService = HealthcareService::factory()->for($division)->create();
        $comment = $this->faker->sentence();
        $description = $this->faker->sentence();
        $ehealthAvailableTime = [['days_of_week' => ['wed'], 'all_day' => true]];
        $notAvailable = [
            [
                'description' => $description,
                'during' => ['startDate' => '15.12.2026', 'startTime' => '10:00', 'endDate' => '15.12.2026', 'endTime' => '12:30']
            ]
        ];
        $ehealthNotAvailable = [
            ['description' => $description, 'during' => ['start' => '2026-12-15T08:00:00Z', 'end' => '2026-12-15T10:30:00Z']]
        ];

        $eHealth = HealthcareServiceApiFake::respond(200, [
            'data' => $this->ehealthHealthcareService($legalEntity, $division, [
                'id' => $healthcareService->uuid,
                'comment' => $comment,
                'available_time' => $ehealthAvailableTime,
                'not_available' => $ehealthNotAvailable
            ])
        ]);

        Livewire::actingAs($this->userWithRole($legalEntity, Role::OWNER))
            ->test(HealthcareServiceUpdate::class, [
                'legalEntity' => $legalEntity,
                'division' => $division,
                'healthcareService' => $healthcareService
            ])
            ->set('form.comment', $comment)
            ->set('form.availableTime', [
                ['daysOfWeek' => ['wed'], 'allDay' => true, 'availableStartTime' => null, 'availableEndTime' => null]
            ])
            ->set('form.notAvailable', $notAvailable)
            ->call('update')
            ->assertHasNoErrors()
            ->assertRedirectToRoute('healthcare-service.index', [$legalEntity]);

        $this->assertCount(1, $eHealth->receivedRequests);
        $request = $eHealth->receivedRequests[0];
        $this->assertSame('PATCH', $request->method());
        $this->assertStringEndsWith("/api/healthcare_services/$healthcareService->uuid", $request->url());
        $this->assertSame($comment, $request['comment']);
        $this->assertSame([['days_of_week' => ['wed'], 'all_day' => true]], $request['available_time']);
        $this->assertSame([
            ['description' => $description, 'during' => ['start' => '2026-12-15T08:00:00Z', 'end' => '2026-12-15T10:30:00Z']]
        ], $request['not_available']);

        $healthcareService->refresh();
        $this->assertSame($comment, $healthcareService->comment);
        $this->assertEquals($ehealthAvailableTime, $healthcareService->availableTime);
        $this->assertEquals($notAvailable, $healthcareService->notAvailable);
    }

    /**
     * The update replaces the fields in eHealth, so a cleared comment and cleared schedules are sent as empty values
     * instead of being left out, and the service is stored without them.
     *
     * @return void
     */
    public function test_cleared_comment_and_schedules_are_sent_to_ehealth_as_empty(): void
    {
        $legalEntity = LegalEntity::factory()->create();
        $division = Division::factory()->for($legalEntity)->create();
        $healthcareService = HealthcareService::factory()->for($division)->create([
            'comment' => $this->faker->sentence(),
            'available_time' => [['days_of_week' => ['mon'], 'all_day' => true]],
            'not_available' => [
                ['description' => $this->faker->sentence(), 'during' => ['start' => '2026-12-01T07:00:00Z', 'end' => '2026-12-01T11:00:00Z']]
            ]
        ]);

        $eHealth = HealthcareServiceApiFake::respond(200, [
            'data' => $this->ehealthHealthcareService($legalEntity, $division, [
                'id' => $healthcareService->uuid,
                'comment' => null,
                'available_time' => [],
                'not_available' => []
            ])
        ]);

        Livewire::actingAs($this->userWithRole($legalEntity, Role::OWNER))
            ->test(HealthcareServiceUpdate::class, [
                'legalEntity' => $legalEntity,
                'division' => $division,
                'healthcareService' => $healthcareService
            ])
            ->set('form.comment', '')
            ->set('form.availableTime', [])
            ->set('form.notAvailable', [])
            ->call('update')
            ->assertHasNoErrors();

        $payload = $eHealth->receivedRequests[0]->data();
        $this->assertArrayHasKey('comment', $payload);
        $this->assertNull($payload['comment']);
        $this->assertSame([], $payload['available_time']);
        $this->assertSame([], $payload['not_available']);

        $healthcareService->refresh();
        $this->assertNull($healthcareService->comment);
        $this->assertSame([], $healthcareService->availableTime);
        $this->assertSame([], $healthcareService->notAvailable);
    }

    /**
     * When eHealth rejects the update, the stored service stays as it was and the user sees the error eHealth returned.
     *
     * @return void
     */
    public function test_failed_ehealth_update_changes_nothing(): void
    {
        $legalEntity = LegalEntity::factory()->create();
        $division = Division::factory()->for($legalEntity)->create();
        $comment = $this->faker->sentence();
        $healthcareService = HealthcareService::factory()->for($division)->create(['comment' => $comment]);

        HealthcareServiceApiFake::respond(500, ['error' => ['message' => 'Internal server error']]);

        $updatePage = Livewire::actingAs($this->userWithRole($legalEntity, Role::OWNER))
            ->test(HealthcareServiceUpdate::class, [
                'legalEntity' => $legalEntity,
                'division' => $division,
                'healthcareService' => $healthcareService
            ])
            ->set('form.comment', $this->faker->sentence());
        $this->travel(1)->seconds();

        $updatePage->call('update')
            ->assertNoRedirect()
            ->assertSee('500: Internal server error');

        $this->assertSame($comment, $healthcareService->refresh()->comment);
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
}
