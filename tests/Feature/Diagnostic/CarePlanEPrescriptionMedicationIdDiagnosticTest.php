<?php

declare(strict_types=1);

namespace Tests\Feature\Diagnostic;

use App\Classes\eHealth\Api\Drug;
use App\Classes\eHealth\Api\MedicalProgram;
use App\Classes\eHealth\Api\Person as PersonApi;
use App\Classes\eHealth\EHealthResponse;
use App\Livewire\CarePlan\Activity\Show\CarePlanActivityShow;
use App\Models\CarePlan;
use App\Models\CarePlanActivity;
use App\Models\Employee\Employee;
use App\Models\LegalEntity;
use App\Models\MedicalEvents\Sql\Encounter;
use App\Models\Person\Person;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Mockery;
use Tests\Concerns\GrantsMedicalEventAbilities;
use Tests\TestCase;

/**
 * Diagnostic: care-plan eRx must submit resolved drug id, not activity INNM dosage reference.
 *
 * @group diagnostic
 * @group eprescription
 */
class CarePlanEPrescriptionMedicationIdDiagnosticTest extends TestCase
{
    use DatabaseTransactions;
    use GrantsMedicalEventAbilities;

    protected Person $person;

    protected User $user;

    protected Employee $employee;

    protected CarePlanActivity $carePlanActivity;

    protected function setUp(): void
    {
        parent::setUp();

        $this->person = Person::create([
            'uuid' => (string) Str::uuid(),
            'birth_date' => '1985-05-15',
            'gender' => 'MALE',
            'patient_signed' => true,
            'process_disclosure_data_consent' => true,
        ]);

        $typeId = \Illuminate\Support\Facades\DB::table('legal_entity_types')->where('name', 'PRIMARY_CARE')->value('id')
            ?? \Illuminate\Support\Facades\DB::table('legal_entity_types')->insertGetId(['name' => 'PRIMARY_CARE']);

        $legalEntity = LegalEntity::create([
            'uuid' => (string) Str::uuid(),
            'status' => 'ACTIVE',
            'sync_status' => 'COMPLETED',
            'legal_entity_type_id' => $typeId,
            'is_active' => true,
        ]);
        $this->instance('legalEntity', $legalEntity);

        $party = \App\Models\Relations\Party::create([
            'uuid' => (string) Str::uuid(),
            'first_name' => 'Іван',
            'last_name' => 'Петренко',
            'tax_id' => '9876543210',
            'birth_date' => '1980-08-08',
            'gender' => 'MALE',
        ]);

        $this->user = User::create([
            'uuid' => (string) Str::uuid(),
            'email' => 'erx_diag_'.Str::random(6).'@example.com',
            'password' => Hash::make('password'),
            'party_id' => $party->id,
        ]);

        $this->employee = Employee::create([
            'uuid' => (string) Str::uuid(),
            'full_name' => 'Д-р Іван Петренко',
            'employee_type' => 'DOCTOR',
            'status' => 'APPROVED',
            'legal_entity_id' => $legalEntity->id,
            'is_active' => true,
            'position' => 'Doctor',
            'start_date' => now()->format('Y-m-d'),
            'user_id' => $this->user->id,
            'party_id' => $party->id,
        ]);
        $this->user->employees()->attach($this->employee->id);

        $episodeId = \App\Models\MedicalEvents\Sql\Identifier::create(['value' => (string) Str::uuid()])->id;
        $codingId = \App\Models\MedicalEvents\Sql\Coding::create([
            'code' => 'AMB',
            'system' => 'eHealth/encounter_classes',
        ])->id;
        $ccId = \App\Models\MedicalEvents\Sql\CodeableConcept::create()->id;
        $performer = \App\Models\MedicalEvents\Sql\Identifier::create(['value' => $this->employee->uuid]);

        $encounter = Encounter::create([
            'uuid' => (string) Str::uuid(),
            'person_id' => $this->person->id,
            'status' => 'finished',
            'episode_id' => $episodeId,
            'class_id' => $codingId,
            'type_id' => $ccId,
            'ehealth_inserted_at' => now(),
            'performer_id' => $performer->id,
        ]);
        $encounter->period()->create([
            'start' => now()->startOfDay(),
            'end' => now(),
        ]);

        if (config('permission.teams')) {
            setPermissionsTeamId($legalEntity->id);
        }
        $this->grantMedicalEventAbilities($this->user);

        $carePlan = CarePlan::create([
            'uuid' => (string) Str::uuid(),
            'person_id' => $this->person->id,
            'author_id' => $this->employee->id,
            'legal_entity_id' => $legalEntity->id,
            'status' => 'active',
            'title' => 'Diagnostic care plan',
            'period_start' => now()->format('Y-m-d'),
        ]);

        $innmDosageId = 'aaaaaaaa-bbbb-cccc-dddd-111111111111';
        $this->carePlanActivity = CarePlanActivity::create([
            'uuid' => (string) Str::uuid(),
            'care_plan_id' => $carePlan->id,
            'author_id' => $this->employee->id,
            'kind' => 'medication_request',
            'product_reference' => $innmDosageId,
            'quantity' => 30,
            'status' => 'scheduled',
            'program' => (string) Str::uuid(),
        ]);
    }

    /**
     * P1: when drug search returns a different id than activity product_reference (INNM dosage),
     * the eRx form must use the resolved drug id as medication_id.
     */
    public function test_care_plan_erx_form_uses_resolved_drug_id_not_innm_dosage_id(): void
    {
        $innmDosageId = $this->carePlanActivity->productReference;
        $resolvedDrugId = 'zzzzzzzz-yyyy-xxxx-wwww-999999999999';

        $this->assertNotSame($innmDosageId, $resolvedDrugId);

        $mockDrugApi = Mockery::mock(Drug::class);
        $drugResponse = Mockery::mock(EHealthResponse::class);
        $drugResponse->shouldReceive('getData')->andReturn([
            [
                'id' => $resolvedDrugId,
                'name' => 'Resolved Trade Name',
                'innm_dosage_form' => 'TAB',
                'packages' => [
                    [
                        'package_min_qty' => 10,
                        'max_request_dosage' => 100,
                    ],
                ],
            ],
        ]);
        $mockDrugApi->shouldReceive('getMany')
            ->with(Mockery::on(static function (array $filters) use ($innmDosageId): bool {
                return ($filters['innm_dosage_id'] ?? null) === $innmDosageId;
            }))
            ->andReturn($drugResponse);
        $this->instance(Drug::class, $mockDrugApi);

        $mockProgramApi = Mockery::mock(MedicalProgram::class);
        $programResponse = Mockery::mock(EHealthResponse::class);
        $programResponse->shouldReceive('getData')->andReturn([
            [
                'id' => $this->carePlanActivity->program,
                'name' => 'Affordable Medicines',
                'settings' => [
                    'skip_treatment_period' => true,
                    'request_max_period_day' => 90,
                ],
            ],
        ]);
        $programResponse->shouldReceive('getPaging')->andReturn(['total_pages' => 1]);
        $mockProgramApi->shouldReceive('asMis')->andReturnSelf();
        $mockProgramApi->shouldReceive('getMany')->andReturn($programResponse);
        $this->instance(MedicalProgram::class, $mockProgramApi);

        $mockPersonApi = Mockery::mock(PersonApi::class);
        $personResponse = Mockery::mock(EHealthResponse::class);
        $personResponse->shouldReceive('getData')->andReturn([
            [
                'uuid' => 'auth-1',
                'type' => 'OTP',
                'phone_number' => '+380991112233',
            ],
        ]);
        $mockPersonApi->shouldReceive('getAuthMethods')->andReturn($personResponse);
        $this->instance(PersonApi::class, $mockPersonApi);

        $mockActivityApi = Mockery::mock(\App\Classes\eHealth\Api\CarePlanActivity::class);
        $activityResponse = Mockery::mock(EHealthResponse::class);
        $activityResponse->shouldReceive('successful')->andReturn(true);
        $activityResponse->shouldReceive('getData')->andReturn(['id' => $this->carePlanActivity->uuid]);
        $mockActivityApi->shouldReceive('getDetails')->andReturn($activityResponse);
        $this->instance(\App\Classes\eHealth\Api\CarePlanActivity::class, $mockActivityApi);

        $this->actingAs($this->user);

        $component = Livewire::test(CarePlanActivityShow::class, [
            'carePlan' => $this->carePlanActivity->carePlan,
            'activity' => $this->carePlanActivity,
        ])
            ->call('initEPrescriptionForm', $this->carePlanActivity->id)
            ->assertSet('showEPrescriptionDrawer', true);

        $actualMedicationId = data_get($component->get('ePrescriptionForm'), 'medication_id');
        $this->assertSame(
            $resolvedDrugId,
            $actualMedicationId,
            "Form medication_id must be resolved drug id ({$resolvedDrugId}), not INNM dosage ({$innmDosageId}). Got: {$actualMedicationId}"
        );
    }
}
