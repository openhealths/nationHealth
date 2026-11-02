<?php

declare(strict_types=1);

namespace Tests\Feature\HealthcareService;

use App\Enums\Division\Type as DivisionType;
use App\Enums\HealthcareService\Status;
use App\Enums\License\Type as LicenseType;
use App\Enums\User\Role;
use App\Livewire\Division\HealthcareService\HealthcareServiceCreate;
use App\Models\Division;
use App\Models\HealthcareService;
use App\Models\LegalEntity;
use App\Models\License;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\MocksBasicDictionaries;
use Tests\TestCase;

/**
 * Creating a healthcare service of the PHARMACY_DRUGS category in an outpatient legal entity. The category needs a
 * service type and an active pharmacy drugs license of the legal entity, and takes no speciality or providing
 * condition.
 */
class HealthcareServicePharmacyDrugsCreateTest extends TestCase
{
    use RefreshDatabase;
    use WithFaker;
    use MocksBasicDictionaries;

    /**
     * Outpatient legal entity the user works in.
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
     * Pharmacy drugs license of the legal entity, suitable for the service.
     *
     * @var License
     */
    private License $license;

    /**
     * Owner of the legal entity, allowed to create healthcare services.
     *
     * @var User
     */
    private User $owner;

    /**
     * Licenses the form doesn't accept for the service, as attributes over a pharmacy drugs license of the legal entity.
     *
     * @return array
     */
    public static function unsuitableLicenses(): array
    {
        return [
            'expired' => [['expiry_date' => '2020-01-01']],
            'inactive' => [['is_active' => false]],
            'of another type' => [['type' => LicenseType::MSP]],
            'of another legal entity' => [['legal_entity_id' => static fn (): int => LegalEntity::factory()->create()->id]]
        ];
    }

    /**
     * The service goes to eHealth with its type and license and without a speciality or providing condition, and is
     * stored with them as eHealth returns it.
     *
     * @return void
     */
    public function test_service_is_sent_with_type_and_license_and_stored(): void
    {
        $uuid = $this->faker->uuid();
        $eHealth = HealthcareServiceApiFake::respond(201, ['data' => $this->ehealthHealthcareService(['id' => $uuid])]);

        $this->filledForm()
            ->call('create')
            ->assertHasNoErrors()
            ->assertRedirectToRoute('healthcare-service.index', [$this->legalEntity, $this->division->id]);

        $this->assertCount(1, $eHealth->receivedRequests);
        $payload = $eHealth->receivedRequests[0]->data();
        $this->assertSame('PHARMACY_DRUGS', $payload['category']['coding'][0]['code']);
        $this->assertSame('SALE', $payload['type']['coding'][0]['code']);
        $this->assertSame($this->license->uuid, $payload['license_id']);
        $this->assertArrayNotHasKey('speciality_type', $payload);
        $this->assertArrayNotHasKey('providing_condition', $payload);

        $healthcareService = HealthcareService::whereUuid($uuid)->firstOrFail();
        $this->assertSame('PHARMACY_DRUGS', $healthcareService->category->coding->first()->code);
        $this->assertSame('SALE', $healthcareService->type->coding->first()->code);
        $this->assertSame($this->license->uuid, $healthcareService->licenseId);
        $this->assertNull($healthcareService->specialityType);
        $this->assertNull($healthcareService->providingCondition);
    }

    /**
     * Without a service type and a license the service is not sent to eHealth.
     *
     * @return void
     */
    public function test_service_without_type_and_license_is_not_sent(): void
    {
        $eHealth = HealthcareServiceApiFake::respond(201, ['data' => $this->ehealthHealthcareService()]);

        Livewire::actingAs($this->owner)
            ->test(HealthcareServiceCreate::class, ['legalEntity' => $this->legalEntity, 'division' => $this->division])
            ->set('form.category.coding.0.code', 'PHARMACY_DRUGS')
            ->call('create')
            ->assertHasErrors(['form.type.coding.0.code', 'form.licenseId'])
            ->assertNoRedirect();

        $this->assertSame([], $eHealth->receivedRequests);
        $this->assertDatabaseCount('healthcare_services', 0);
    }

    /**
     * A license the legal entity can't use for the service is rejected, and nothing is sent to eHealth.
     *
     * @param  array  $licenseAttributes
     * @return void
     */
    #[DataProvider('unsuitableLicenses')]
    public function test_unsuitable_license_is_rejected(array $licenseAttributes): void
    {
        $license = License::factory()
            ->for($this->legalEntity)
            ->create(['type' => LicenseType::PHARMACY_DRUGS, ...$licenseAttributes]);

        $eHealth = HealthcareServiceApiFake::respond(201, ['data' => $this->ehealthHealthcareService()]);

        $this->filledForm()
            ->set('form.licenseId', $license->uuid)
            ->call('create')
            ->assertHasErrors('form.licenseId')
            ->assertNoRedirect();

        $this->assertSame([], $eHealth->receivedRequests);
        $this->assertDatabaseCount('healthcare_services', 0);
    }

    /**
     * Create the outpatient legal entity with its division, license and owner, and cache the dictionaries the page
     * uses.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->legalEntity = LegalEntity::factory()->ofType(LegalEntity::TYPE_OUTPATIENT)->create();
        $this->division = Division::factory()->for($this->legalEntity)->create(['type' => DivisionType::TYPE_LICENSED_UNIT->value]);
        $this->license = License::factory()->for($this->legalEntity)->create(['type' => LicenseType::PHARMACY_DRUGS]);

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
     * Create page opened by the owner with the form filled for selling drugs under the legal entity's license.
     *
     * @return Testable
     */
    private function filledForm(): Testable
    {
        return Livewire::actingAs($this->owner)
            ->test(HealthcareServiceCreate::class, ['legalEntity' => $this->legalEntity, 'division' => $this->division])
            ->set('form.category.coding.0.code', 'PHARMACY_DRUGS')
            ->set('form.type.coding.0.code', 'SALE')
            ->set('form.licenseId', $this->license->uuid);
    }

    /**
     * Pharmacy drugs healthcare service in the shape the eHealth "create healthcare service" endpoint returns it.
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
            'category' => ['coding' => [['system' => 'HEALTHCARE_SERVICE_CATEGORIES', 'code' => 'PHARMACY_DRUGS']]],
            'type' => ['coding' => [['system' => 'HEALTHCARE_SERVICE_PHARMACY_DRUGS_TYPES', 'code' => 'SALE']]],
            'license_id' => $this->license->uuid,
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
