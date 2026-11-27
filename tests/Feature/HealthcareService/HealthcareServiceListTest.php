<?php

declare(strict_types=1);

namespace Tests\Feature\HealthcareService;

use App\Enums\User\Role;
use App\Livewire\Division\HealthcareService\HealthcareServiceIndex;
use App\Models\Division;
use App\Models\HealthcareService;
use App\Models\LegalEntity;
use App\Models\MedicalEvents\Sql\CodeableConcept;
use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\MocksBasicDictionaries;
use Tests\Concerns\RefreshInstalledDatabase;
use Tests\TestCase;

/**
 * The list of healthcare services stored for the legal entity: the values it shows for each service and the filters
 * it is narrowed by.
 */
class HealthcareServiceListTest extends TestCase
{
    use MocksBasicDictionaries;
    use PreparesHealthcareServiceTests;
    use RefreshInstalledDatabase;
    use WithFaker;

    protected LegalEntity $legalEntity;

    protected HealthcareService $familyDoctorService;

    protected HealthcareService $pharmacyService;

    /**
     * Filters of the list: the property, the value chosen, and the service it leaves.
     *
     * @return array
     */
    public static function filters(): array
    {
        return [
            'speciality type' => ['filterSpecialityType', 'FAMILY_DOCTOR', 'familyDoctorService'],
            'category' => ['filterCategory', 'PHARMACY_DRUGS', 'pharmacyService'],
            'type' => ['filterType', 'SALE', 'pharmacyService'],
            'providing condition' => ['filterProvidingCondition', 'OUTPATIENT', 'familyDoctorService'],
        ];
    }

    /**
     * Each filter leaves only the services that match it.
     *
     * @param  string  $filter
     * @param  string  $value
     * @param  string  $expectedService
     * @return void
     */
    #[DataProvider('filters')]
    public function test_filter_leaves_only_matching_services(string $filter, string $value, string $expectedService): void
    {
        $expectedId = $this->{$expectedService}->id;

        $this->listPage()
            ->set($filter, $value)
            ->call('search')
            ->assertViewHas(
                'healthcareServices',
                static fn(LengthAwarePaginator $services): bool => $services->pluck('id')->all() === [$expectedId],
            );
    }

    /**
     * The list gives each service its category, type and status under its license, which the table shows.
     *
     * @return void
     */
    public function test_list_provides_category_type_and_licensed_status(): void
    {
        $this->listPage()
            ->set('filterCategory', 'PHARMACY_DRUGS')
            ->call('search')
            ->assertViewHas('healthcareServices', static function (LengthAwarePaginator $services): bool {
                $service = $services->sole();

                return $service->category->coding->first()->code === 'PHARMACY_DRUGS'
                    && $service->type->coding->first()->code === 'SALE'
                    && $service->licensedHealthcareService['status'] === 'ACTIVE';
            });
    }

    /**
     * An MSP family doctor service and a pharmacy drugs sale service with a license status, in one division.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->mockBasicDictionaries();

        $this->legalEntity = LegalEntity::factory()->create();
        $division = Division::factory()->for($this->legalEntity)->create();

        $this->familyDoctorService = HealthcareService::factory()->for($division)->create();
        $this->pharmacyService = HealthcareService::factory()->for($division)->create([
            'category_id' => CodeableConcept::factory()->hasCoding(['system' => 'HEALTHCARE_SERVICE_CATEGORIES', 'code' => 'PHARMACY_DRUGS']),
            'type_id' => CodeableConcept::factory()->hasCoding(['system' => 'HEALTHCARE_SERVICE_PHARMACY_DRUGS_TYPES', 'code' => 'SALE']),
            'speciality_type' => null,
            'providing_condition' => null,
            'licensed_healthcare_service' => ['status' => 'ACTIVE', 'updated_at' => '2026-11-01T10:00:00Z'],
        ]);
    }

    /**
     * The list page opened by the owner of the legal entity.
     *
     * @return Testable
     */
    protected function listPage(): Testable
    {
        return Livewire::actingAs($this->userWithRole($this->legalEntity, Role::OWNER))
            ->test(HealthcareServiceIndex::class, ['legalEntity' => $this->legalEntity]);
    }
}
