<?php

declare(strict_types=1);

namespace Tests\Feature\CarePlan;

use App\Classes\eHealth\Api\DeviceDefinition;
use App\Classes\eHealth\EHealthResponse;
use App\Enums\Status;
use App\Enums\User\Role;
use App\Livewire\CarePlan\Activity\Show\CarePlanActivityShow;
use App\Livewire\CarePlan\CarePlanShow;
use App\Livewire\CarePlan\Forms\CarePlanActivityForm;
use App\Models\CarePlanActivity;
use App\Models\Employee\Employee;
use App\Models\LegalEntity;
use App\Models\MedicalEvents\Sql\Encounter;
use App\Models\MedicalEvents\Sql\Identifier;
use App\Models\MedicalEvents\Sql\Observation;
use App\Models\Person\Person;
use App\Models\Relations\Party;
use App\Models\User;

use App\Repositories\MedicalEvents\Repository as MedicalEventsRepository;
use App\Repositories\Repository;
use App\Services\Dictionary\Dictionaries\MedicalProgramDictionary;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Mockery;
use Tests\Concerns\CreatesFhirCarePlans;
use Tests\TestCase;

class CarePlanActivityValidationsTest extends TestCase
{
    use CreatesFhirCarePlans;

    use DatabaseTransactions;

    protected Person $person;

    protected User $user;

    protected Employee $employee;

    protected LegalEntity $legalEntity;

    protected function setUp(): void
    {
        parent::setUp();

        config(['cipher.api.domain' => 'https://cipher.invalid']);
        Cache::put('knedp_certificate_authority', [], 60);

        $this->person = Person::create([
            'uuid' => (string) Str::uuid(),
            'first_name' => 'Phase5',
            'last_name' => 'Patient',
            'birth_date' => '1995-03-03',
            'gender' => 'FEMALE',
            'patient_signed' => true,
            'process_disclosure_data_consent' => true,
        ]);

        $typeId = DB::table('legal_entity_types')->where('name', 'PRIMARY_CARE')->value('id')
            ?? DB::table('legal_entity_types')->insertGetId(['name' => 'PRIMARY_CARE']);

        $this->legalEntity = LegalEntity::create([
            'uuid' => (string) Str::uuid(),
            'status' => 'ACTIVE',
            'sync_status' => 'COMPLETED',
            'legal_entity_type_id' => $typeId,
            'is_active' => true,
        ]);
        $this->instance('legalEntity', $this->legalEntity);

        $party = Party::create([
            'uuid' => (string) Str::uuid(),
            'first_name' => 'Phase5',
            'last_name' => 'Doctor',
            'tax_id' => '2233445566',
            'birth_date' => '1978-01-01',
            'gender' => 'FEMALE',
        ]);

        $this->user = User::create([
            'uuid' => (string) Str::uuid(),
            'email' => 'phase5_' . Str::random(6) . '@example.com',
            'password' => Hash::make('password'),
            'party_id' => $party->id,
        ]);

        $this->employee = Employee::create([
            'uuid' => (string) Str::uuid(),
            'full_name' => 'Dr Phase5',
            'employee_type' => Role::DOCTOR->value,
            'status' => Status::APPROVED->value,
            'legal_entity_id' => $this->legalEntity->id,
            'is_active' => true,
            'position' => 'Doctor',
            'start_date' => now()->format('Y-m-d'),
            'user_id' => $this->user->id,
            'party_id' => $party->id,
        ]);

        $this->user->employees()->attach($this->employee->id);

        if (config('permission.teams')) {
            setPermissionsTeamId($this->legalEntity->id);
        }

        $this->grantMedicalEventAbilities($this->user);
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function seedMedicalProgram(string $programId, string $name, array $settings, string $type = 'MEDICATION'): void
    {
        Cache::put(MedicalProgramDictionary::KEY, [
            [
                'id' => $programId,
                'name' => $name,
                'type' => $type,
                'is_active' => true,
                'medical_program_settings' => $settings,
            ],
        ], now()->addHour());
        Cache::put(MedicalProgramDictionary::KEY . ':fresh', true, now()->addHour());
    }

    public function test_blocks_medication_activity_when_providing_conditions_mismatch(): void
    {
        $this->actingAs($this->user);

        $carePlan = $this->createFhirCarePlan([
            'uuid' => (string) Str::uuid(),
            'person_id' => $this->person->id,
            'author_id' => $this->employee->id,
            'legal_entity_id' => $this->legalEntity->id,
            'period_start' => now()->format('Y-m-d'),
            'title' => 'Providing Conditions Plan',
            'status' => 'active',
            'terms_of_service' => 'OUTPATIENT',
        ]);
        $this->grantCarePlanWriteApproval($carePlan, $this->employee);

        $programId = (string) Str::uuid();
        $medicationId = (string) Str::uuid();
        $this->seedMedicalProgram($programId, 'Restricted program', [
            'providing_conditions_allowed' => ['INPATIENT'],
        ]);

        Livewire::test(CarePlanShow::class, ['carePlan' => $carePlan])
            ->call('initActivityForm', 'medication_request')
            ->set('selectedProgram', $programId)
            ->set('activityForm.program', $programId)
            ->call('selectProduct', [
                'id' => $medicationId,
                'name' => 'Aspirin',
                'ingredients' => [
                    ['dosage' => ['denumerator_unit' => 'PIECE']],
                ],
                'packages' => [
                    ['package_min_qty' => 1],
                ],
            ], 'medication_request')
            ->set('activityForm.product_reference', $medicationId)
            ->set('activityForm.quantity_system', 'MEDICATION_UNIT')
            ->set('activityForm.quantity_code', 'PIECE')
            ->set('activityForm.quantity', 10)
            ->set('activityForm.daily_amount', 1)
            ->set('activityForm.scheduled_period_start', now()->format('d.m.Y'))
            ->set('activityForm.scheduled_period_end', now()->addDays(7)->format('d.m.Y'))
            ->call('saveActivity')
            ->assertHasErrors(['activityForm.program']);

        $this->assertDatabaseMissing('care_plan_activities', [
            'care_plan_id' => $carePlan->id,
            'product_reference' => $medicationId,
        ]);
    }

    public function test_allows_medication_activity_when_providing_conditions_match(): void
    {
        $this->actingAs($this->user);

        $carePlan = $this->createFhirCarePlan([
            'uuid' => (string) Str::uuid(),
            'person_id' => $this->person->id,
            'author_id' => $this->employee->id,
            'legal_entity_id' => $this->legalEntity->id,
            'period_start' => now()->format('Y-m-d'),
            'title' => 'Matching Conditions Plan',
            'status' => 'active',
            'terms_of_service' => 'OUTPATIENT',
        ]);
        $this->grantCarePlanWriteApproval($carePlan, $this->employee);

        $programId = (string) Str::uuid();
        $medicationId = (string) Str::uuid();
        $this->seedMedicalProgram($programId, 'Outpatient program', [
            'providing_conditions_allowed' => ['OUTPATIENT'],
        ]);

        Livewire::test(CarePlanShow::class, ['carePlan' => $carePlan])
            ->call('initActivityForm', 'medication_request')
            ->set('selectedProgram', $programId)
            ->set('activityForm.program', $programId)
            ->call('selectProduct', [
                'id' => $medicationId,
                'name' => 'Aspirin',
                'ingredients' => [
                    ['dosage' => ['denumerator_unit' => 'PIECE']],
                ],
                'packages' => [
                    ['package_min_qty' => 1],
                ],
            ], 'medication_request')
            ->set('activityForm.product_reference', $medicationId)
            ->set('activityForm.quantity_system', 'MEDICATION_UNIT')
            ->set('activityForm.quantity_code', 'PIECE')
            ->set('activityForm.quantity', 10)
            ->set('activityForm.daily_amount', 1)
            ->set('activityForm.scheduled_period_start', now()->format('d.m.Y'))
            ->set('activityForm.scheduled_period_end', now()->addDays(7)->format('d.m.Y'))
            ->call('saveActivity')
            ->assertHasNoErrors();

        $activity = CarePlanActivity::whereCarePlanId($carePlan->id)->firstOrFail();
        $resource = Repository::carePlanActivity()->getResource($activity->id);
        $this->assertSame($medicationId, data_get($resource, 'detail.productReference.identifier.value'));
        $this->assertSame($programId, data_get($resource, 'detail.program.identifier.value'));
        $this->assertNull($activity->getRawOriginal('product_reference'));
    }

    public function test_blocks_activity_without_reason_reference_on_rehab_plan(): void
    {
        $this->actingAs($this->user);

        $carePlan = $this->createFhirCarePlan([
            'uuid' => (string) Str::uuid(),
            'person_id' => $this->person->id,
            'author_id' => $this->employee->id,
            'legal_entity_id' => $this->legalEntity->id,
            'period_start' => now()->format('Y-m-d'),
            'title' => 'Rehab Plan',
            'status' => 'active',
            'category' => 'CLASS 23',
            'terms_of_service' => 'OUTPATIENT',
        ]);
        $this->grantCarePlanWriteApproval($carePlan, $this->employee);

        Livewire::test(CarePlanShow::class, ['carePlan' => $carePlan])
            ->call('initActivityForm', 'service_request')
            ->call('selectProduct', ['code' => 'A01001', 'name' => 'Rehab service'], 'service_request')
            ->set('activityForm.product_reference', 'A01001')
            ->set('activityForm.quantity', 1)
            ->set('activityForm.scheduled_period_start', now()->format('d.m.Y'))
            ->set('activityForm.scheduled_period_end', now()->addDays(7)->format('d.m.Y'))
            ->call('saveActivity')
            ->assertHasErrors(['linkedGrounds']);

        $this->assertDatabaseMissing('care_plan_activities', [
            'care_plan_id' => $carePlan->id,
            'product_reference' => 'A01001',
        ]);
    }

    public function test_allows_rehab_activity_with_reason_reference(): void
    {
        $this->actingAs($this->user);

        $carePlan = $this->createFhirCarePlan([
            'uuid' => (string) Str::uuid(),
            'person_id' => $this->person->id,
            'author_id' => $this->employee->id,
            'legal_entity_id' => $this->legalEntity->id,
            'period_start' => now()->format('Y-m-d'),
            'title' => 'Rehab Plan OK',
            'status' => 'active',
            'category' => 'CLASS 25',
            'terms_of_service' => 'OUTPATIENT',
        ]);
        $this->grantCarePlanWriteApproval($carePlan, $this->employee);

        $observationUuid = (string) Str::uuid();
        $episode = Identifier::create(['value' => (string) Str::uuid()]);
        $class = MedicalEventsRepository::coding()->store([
            'system' => 'eHealth/encounter_classes', 'code' => 'AMB',
        ]);
        $type = MedicalEventsRepository::codeableConcept()->store([
            'coding' => [['system' => 'eHealth/encounter_types', 'code' => 'TEST']],
        ]);
        $encounter = Encounter::create([
            'uuid' => (string) Str::uuid(), 'person_id' => $this->person->id, 'status' => 'finished',
            'episode_id' => $episode->id, 'class_id' => $class->id, 'type_id' => $type->id,
        ]);
        $carePlan->update(['encounter_id' => $encounter->id]);
        $context = Identifier::create(['value' => $encounter->uuid]);
        $code = MedicalEventsRepository::codeableConcept()->store([
            'coding' => [['system' => 'eHealth/ICF/classifiers', 'code' => 'b280']],
        ]);
        $observation = Observation::create([
            'uuid' => $observationUuid, 'person_id' => $this->person->id, 'status' => 'valid',
            'context_id' => $context->id, 'code_id' => $code->id, 'issued' => now(), 'primary_source' => true,
        ]);
        $category = MedicalEventsRepository::codeableConcept()->store([
            'coding' => [['system' => 'eHealth/ICF/observation_categories', 'code' => 'functions']],
        ]);
        $observation->categories()->attach($category->id);

        Livewire::test(CarePlanShow::class, ['carePlan' => $carePlan])
            ->call('initActivityForm', 'service_request')
            ->call('selectProduct', ['code' => 'A01002', 'name' => 'Rehab service'], 'service_request')
            ->set('activityForm.product_reference', 'A01002')
            ->call('addLinkedGround', 'Observation', $observationUuid)
            ->set('activityForm.quantity', 2)
            ->set('activityForm.scheduled_period_start', now()->format('d.m.Y'))
            ->set('activityForm.scheduled_period_end', now()->addDays(7)->format('d.m.Y'))
            ->call('saveActivity')
            ->assertHasNoErrors();

        $activity = CarePlanActivity::where('care_plan_id', $carePlan->id)->first();
        $this->assertNotNull($activity);
        $resource = Repository::carePlanActivity()->getResource($activity->id);
        $this->assertSame('observation', data_get($resource, 'detail.reasonReference.0.identifier.type.coding.0.code'));
        $this->assertSame($observationUuid, data_get($resource, 'detail.reasonReference.0.identifier.value'));
    }

    public function test_activity_detail_card_renders_tv_fields(): void
    {
        $this->actingAs($this->user);

        $carePlan = $this->createFhirCarePlan([
            'uuid' => (string) Str::uuid(),
            'person_id' => $this->person->id,
            'author_id' => $this->employee->id,
            'legal_entity_id' => $this->legalEntity->id,
            'period_start' => now()->format('Y-m-d'),
            'title' => 'Display Plan',
            'status' => 'active',
        ]);
        $this->grantCarePlanWriteApproval($carePlan, $this->employee);

        $activity = $this->createFhirCarePlanActivity([
            'uuid' => (string) Str::uuid(),
            'care_plan_id' => $carePlan->id,
            'author_id' => $this->employee->id,
            'status' => 'scheduled',
            'kind' => 'medication_request',
            'product_reference' => (string) Str::uuid(),
            'reason_code' => 'I10',
            'reason_reference' => ['Condition/' . (string) Str::uuid()],
            'goal' => ['pain_control'],
            'quantity' => 30,
            'quantity_code' => 'PIECE',
            'remaining_quantity' => 20,
            'remaining_quantity_code' => 'PIECE',
            'daily_amount' => 1.5,
            'daily_amount_code' => 'PIECE',
            'program' => (string) Str::uuid(),
            'description' => 'TV detail coverage',
            'status_reason' => 'adjusted',
            'scheduled_period_start' => now()->format('Y-m-d'),
            'scheduled_period_end' => now()->addDays(10)->format('Y-m-d'),
        ]);

        Livewire::test(CarePlanActivityShow::class, [
            'carePlan' => $carePlan,
            'activity' => $activity,
        ])
            ->assertSee(__('care-plan.author'))
            ->assertSee(__('care-plan.grounds_for_prescription'))
            ->assertSee(__('care-plan.justification_of_grounds'))
            ->assertSee(__('care-plan.expected_result'))
            ->assertSee(__('care-plan.remaining_quantity'))
            ->assertSee(__('care-plan.daily_amount'))
            ->assertSee(__('forms.synchronise_with_eHealth'))
            ->assertSee('I10')
            ->assertSee('pain_control')
            ->assertSee('20')
            ->assertSee('TV detail coverage');
    }

    public function test_edit_device_activity_loads_its_name_by_uuid_and_signs_integer_after_database_roundtrip(): void
    {
        $this->actingAs($this->user);
        $plan = $this->createFhirCarePlan([
            'person_id' => $this->person->id, 'author_id' => $this->employee->id,
            'legal_entity_id' => $this->legalEntity->id, 'status' => 'active',
        ]);
        $this->grantCarePlanWriteApproval($plan, $this->employee);
        $deviceUuid = (string) Str::uuid();
        $activity = $this->createFhirCarePlanActivity([
            'care_plan_id' => $plan->id, 'author_id' => $this->employee->id,
            'kind' => 'device_request', 'status' => 'draft', 'product_reference' => $deviceUuid,
            'quantity' => 1, 'quantity_system' => 'device_unit', 'quantity_code' => 'piece',
        ]);
        $api = Mockery::mock(DeviceDefinition::class);
        $response = Mockery::mock(EHealthResponse::class);
        $response->shouldReceive('validate')->andReturn([
            'id' => $deviceUuid, 'device_names' => [['name' => 'Тестова система контролю глюкози']],
        ]);
        $api->shouldReceive('getById')->once()->with($deviceUuid)->andReturn($response);
        $api->shouldNotReceive('getMany');
        $this->instance(DeviceDefinition::class, $api);
        Livewire::test(CarePlanShow::class, ['carePlan' => $plan])
            ->call('editActivity', $activity->id)
            ->assertSet('selectedProduct.name', 'Тестова система контролю глюкози')
            ->assertSee('Тестова система контролю глюкози')
            ->assertSee('UUID: '.$deviceUuid);

        $resource = Repository::carePlanActivity()->getResource($activity->id);
        $payload = CarePlanActivityForm::buildCreateContent($resource);
        $decoded = json_decode(json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(1, $decoded['detail']['quantity']['value']);
        $this->assertSame($deviceUuid, $decoded['detail']['product_reference']['identifier']['value']);
    }

    public function test_blocks_device_activity_when_providing_conditions_mismatch(): void
    {
        $this->actingAs($this->user);

        $carePlan = $this->createFhirCarePlan([
            'uuid' => (string) Str::uuid(),
            'person_id' => $this->person->id,
            'author_id' => $this->employee->id,
            'legal_entity_id' => $this->legalEntity->id,
            'period_start' => now()->format('Y-m-d'),
            'title' => 'Inpatient Device Plan',
            'status' => 'active',
            'terms_of_service' => 'INPATIENT',
        ]);
        $this->grantCarePlanWriteApproval($carePlan, $this->employee);

        $programId = '85953838-1834-4ed6-8bf4-3f83057380ec';
        $deviceId = '0fa1e6cd-7066-4881-92a5-6d747a1128f7';
        $this->seedMedicalProgram($programId, 'Glucose strips', [
            'providing_conditions_allowed' => ['OUTPATIENT'],
        ], 'DEVICE');

        Livewire::test(CarePlanShow::class, ['carePlan' => $carePlan])
            ->call('initActivityForm', 'device_request')
            ->set('selectedProgram', $programId)
            ->set('activityForm.program', $programId)
            ->set('activityForm.product_reference', $deviceId)
            ->set('activityForm.quantity', 200)
            ->set('activityForm.scheduled_period_start', now()->format('d.m.Y'))
            ->set('activityForm.scheduled_period_end', now()->addMonths(3)->format('d.m.Y'))
            ->call('saveActivity')
            ->assertHasErrors(['activityForm.program']);

        $this->assertDatabaseMissing('care_plan_activities', [
            'care_plan_id' => $carePlan->id,
            'product_reference' => $deviceId,
        ]);
    }

    public function test_inpatient_device_form_does_not_default_to_outpatient_only_program(): void
    {
        $this->actingAs($this->user);

        $carePlan = $this->createFhirCarePlan([
            'uuid' => (string) Str::uuid(),
            'person_id' => $this->person->id,
            'author_id' => $this->employee->id,
            'legal_entity_id' => $this->legalEntity->id,
            'period_start' => now()->format('Y-m-d'),
            'title' => 'Inpatient No Default Program',
            'status' => 'active',
            'terms_of_service' => 'INPATIENT',
        ]);
        $this->grantCarePlanWriteApproval($carePlan, $this->employee);

        $this->seedMedicalProgram('85953838-1834-4ed6-8bf4-3f83057380ec', 'Glucose strips', [
            'providing_conditions_allowed' => ['OUTPATIENT'],
        ], 'DEVICE');

        Livewire::test(CarePlanShow::class, ['carePlan' => $carePlan])
            ->call('initActivityForm', 'device_request')
            ->assertSet('selectedProgram', '')
            ->assertSet('activityForm.program', '');
    }
}
