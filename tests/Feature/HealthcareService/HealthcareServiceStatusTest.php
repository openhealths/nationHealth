<?php

declare(strict_types=1);

namespace Tests\Feature\HealthcareService;

use App\Enums\HealthcareService\Status;
use App\Enums\Status as DivisionStatus;
use App\Enums\User\Role;
use App\Livewire\Division\HealthcareService\HealthcareServiceIndex;
use App\Models\Division;
use App\Models\HealthcareService;
use App\Models\LegalEntity;
use Illuminate\Foundation\Testing\WithFaker;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\MocksBasicDictionaries;
use Tests\Concerns\RefreshInstalledDatabase;
use Tests\TestCase;

/**
 * Activating and deactivating a healthcare service registered in eHealth from the list page.
 *
 * Only eHealth is faked. The tests go in through the page and check the request sent to eHealth and the status
 * stored from its answer.
 */
class HealthcareServiceStatusTest extends TestCase
{
    use MocksBasicDictionaries;
    use PreparesHealthcareServiceTests;
    use RefreshInstalledDatabase;
    use WithFaker;

    /**
     * Status changes offered on the list: the action, the stored service before it, the status and activity eHealth
     * returns, and the message the user sees.
     *
     * @return array
     */
    public static function statusChanges(): array
    {
        return [
            'deactivate' => [
                'deactivate',
                ['status' => Status::ACTIVE, 'is_active' => true],
                Status::INACTIVE,
                false,
                'healthcare-services.success.deactivated',
            ],
            'activate' => [
                'activate',
                ['status' => Status::INACTIVE, 'is_active' => false],
                Status::ACTIVE,
                true,
                'healthcare-services.success.activated',
            ],
        ];
    }

    /**
     * The status is changed in eHealth through the action's own endpoint, and the service is stored with the status
     * eHealth returns.
     *
     * @param  string  $action
     * @param  array  $storedService
     * @param  Status  $returnedStatus
     * @param  bool  $returnedIsActive
     * @param  string  $successMessage
     * @return void
     */
    #[DataProvider('statusChanges')]
    public function test_status_is_changed_in_ehealth_and_stored_as_returned(
        string $action,
        array $storedService,
        Status $returnedStatus,
        bool $returnedIsActive,
        string $successMessage,
    ): void {
        $legalEntity = LegalEntity::factory()->create();
        $division = Division::factory()->for($legalEntity)->create();
        $healthcareService = HealthcareService::factory()->for($division)->create($storedService);

        $eHealth = HealthcareServiceApiFake::respond(200, [
            'data' => $this->ehealthHealthcareService($legalEntity, $division, [
                'id' => $healthcareService->uuid,
                'status' => $returnedStatus->value,
                'is_active' => $returnedIsActive,
            ]),
        ]);

        $listPage = Livewire::actingAs($this->userWithRole($legalEntity, Role::OWNER))
            ->test(HealthcareServiceIndex::class, ['legalEntity' => $legalEntity]);
        $this->travel(1)->seconds();

        $listPage->call($action, $healthcareService->id)
            ->assertSee(__($successMessage));

        $this->assertCount(1, $eHealth->receivedRequests);
        $this->assertSame('PATCH', $eHealth->receivedRequests[0]->method());
        $this->assertStringEndsWith(
            "/api/healthcare_services/$healthcareService->uuid/actions/$action",
            $eHealth->receivedRequests[0]->url(),
        );

        $healthcareService->refresh();
        $this->assertSame($returnedStatus, $healthcareService->status);
        $this->assertSame($returnedIsActive, $healthcareService->isActive);
    }

    /**
     * A service of an inactive division can't be activated, so the list doesn't offer it and the action is refused.
     *
     * @return void
     */
    public function test_service_of_inactive_division_cannot_be_activated(): void
    {
        $legalEntity = LegalEntity::factory()->create();
        $division = Division::factory()->for($legalEntity)->create(['status' => DivisionStatus::INACTIVE]);
        $healthcareService = HealthcareService::factory()->for($division)->inactive()->create();

        $owner = $this->userWithRole($legalEntity, Role::OWNER);

        $this->assertFalse($owner->can('activate', $healthcareService));
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
