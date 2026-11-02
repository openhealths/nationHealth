<?php

declare(strict_types=1);

namespace Tests\Feature\HealthcareService;

use App\Enums\Division\Type as DivisionType;
use App\Enums\License\Type as LicenseType;
use App\Enums\User\Role;
use App\Livewire\Division\HealthcareService\HealthcareServiceCreate;
use App\Models\Division;
use App\Models\HealthcareService;
use App\Models\LegalEntity;
use App\Models\License;
use App\Models\MedicalEvents\Sql\CodeableConcept;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\MocksBasicDictionaries;
use Tests\TestCase;

/**
 * A division holds only one service registered in eHealth with the same speciality and providing condition, and only
 * one with the same category and type, so a duplicate is rejected when it is created, before it reaches eHealth.
 *
 * The legal entity is outpatient, since it may create services of both categories the checks concern: MSP and
 * PHARMACY_DRUGS. eHealth is faked only to prove that no request goes out.
 */
class HealthcareServiceDuplicateTest extends TestCase
{
    use RefreshDatabase;
    use MocksBasicDictionaries;

    /**
     * Outpatient legal entity the user works in.
     *
     * @var LegalEntity
     */
    private LegalEntity $legalEntity;

    /**
     * Division the services belong to.
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
     * A second service with the same speciality and providing condition is rejected.
     *
     * @return void
     */
    public function test_service_duplicating_speciality_and_providing_condition_is_rejected(): void
    {
        HealthcareService::factory()->for($this->division)->create([
            'speciality_type' => 'FAMILY_DOCTOR',
            'providing_condition' => 'OUTPATIENT'
        ]);

        $eHealth = HealthcareServiceApiFake::respond(201, []);

        Livewire::actingAs($this->owner)
            ->test(HealthcareServiceCreate::class, ['legalEntity' => $this->legalEntity, 'division' => $this->division])
            ->set('form.category.coding.0.code', 'MSP')
            ->set('form.specialityType', 'FAMILY_DOCTOR')
            ->set('form.providingCondition', 'OUTPATIENT')
            ->call('create')
            ->assertHasErrors('unique_combination')
            ->assertNoRedirect();

        $this->assertSame([], $eHealth->receivedRequests);
        $this->assertDatabaseCount('healthcare_services', 1);
    }

    /**
     * A second service with the same category and type is rejected.
     *
     * @return void
     */
    public function test_service_duplicating_category_and_type_is_rejected(): void
    {
        $license = License::factory()->for($this->legalEntity)->create(['type' => LicenseType::PHARMACY_DRUGS]);

        HealthcareService::factory()->for($this->division)->create([
            'category_id' => CodeableConcept::factory()->hasCoding(['system' => 'HEALTHCARE_SERVICE_CATEGORIES', 'code' => 'PHARMACY_DRUGS']),
            'type_id' => CodeableConcept::factory()->hasCoding(['system' => 'HEALTHCARE_SERVICE_PHARMACY_DRUGS_TYPES', 'code' => 'SALE']),
            'speciality_type' => null,
            'providing_condition' => null,
            'license_id' => $license->uuid
        ]);

        $eHealth = HealthcareServiceApiFake::respond(201, []);

        Livewire::actingAs($this->owner)
            ->test(HealthcareServiceCreate::class, ['legalEntity' => $this->legalEntity, 'division' => $this->division])
            ->set('form.category.coding.0.code', 'PHARMACY_DRUGS')
            ->set('form.type.coding.0.code', 'SALE')
            ->set('form.licenseId', $license->uuid)
            ->call('create')
            ->assertHasErrors('unique_combination')
            ->assertNoRedirect();

        $this->assertSame([], $eHealth->receivedRequests);
        $this->assertDatabaseCount('healthcare_services', 1);
    }

    /**
     * Create the outpatient legal entity with its division and owner, and cache the dictionaries the page uses.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->legalEntity = LegalEntity::factory()->ofType(LegalEntity::TYPE_OUTPATIENT)->create();
        $this->division = Division::factory()->for($this->legalEntity)->create(['type' => DivisionType::TYPE_LICENSED_UNIT->value]);

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
}
