<?php

declare(strict_types=1);

namespace Tests\Feature\CarePlan;

use App\Models\CarePlan;
use App\Models\CarePlanActivity;
use App\Models\Employee\Employee;
use App\Models\LegalEntity;
use App\Models\Person\Person;
use App\Models\User;
use App\Services\Dictionary\Dictionaries\MedicalProgramDictionary;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class CarePlanActivityValidationsTest extends TestCase
{
    use DatabaseTransactions;

    protected Person $person;

    protected User $user;

    protected Employee $employee;

    protected LegalEntity $legalEntity;

    protected function setUp(): void
    {
        parent::setUp();

        $this->person = Person::create([
            'uuid' => (string) Str::uuid(),
            'first_name' => 'Phase5',
            'last_name' => 'Patient',
            'birth_date' => '1995-03-03',
            'gender' => 'FEMALE',
            'patient_signed' => true,
            'process_disclosure_data_consent' => true,
        ]);

        $typeId = \Illuminate\Support\Facades\DB::table('legal_entity_types')->where('name', 'PRIMARY_CARE')->value('id')
            ?? \Illuminate\Support\Facades\DB::table('legal_entity_types')->insertGetId(['name' => 'PRIMARY_CARE']);

        $this->legalEntity = LegalEntity::create([
            'uuid' => (string) Str::uuid(),
            'status' => 'ACTIVE',
            'sync_status' => 'COMPLETED',
            'legal_entity_type_id' => $typeId,
            'is_active' => true,
        ]);
        $this->instance('legalEntity', $this->legalEntity);

        $party = \App\Models\Relations\Party::create([
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
            'employee_type' => \App\Enums\User\Role::DOCTOR->value,
            'status' => \App\Enums\Status::APPROVED->value,
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
    private function seedMedicalProgram(string $programId, string $name, array $settings): void
    {
        Cache::put(MedicalProgramDictionary::KEY, [
            [
                'id' => $programId,
                'name' => $name,
                'type' => 'MEDICATION',
                'is_active' => true,
                'medical_program_settings' => $settings,
            ],
        ], now()->addHour());
        Cache::put(MedicalProgramDictionary::KEY . ':fresh', true, now()->addHour());
    }

    public function test_blocks_medication_activity_when_providing_conditions_mismatch(): void
    {
        $this->actingAs($this->user);

        $carePlan = CarePlan::create([
            'uuid' => (string) Str::uuid(),
            'person_id' => $this->person->id,
            'author_id' => $this->employee->id,
            'legal_entity_id' => $this->legalEntity->id,
            'period_start' => now()->format('Y-m-d'),
            'title' => 'Providing Conditions Plan',
            'status' => 'draft',
            'terms_of_service' => 'OUTPATIENT',
        ]);

        $programId = (string) Str::uuid();
        $medicationId = (string) Str::uuid();
        $this->seedMedicalProgram($programId, 'Restricted program', [
            'providing_conditions_allowed' => ['INPATIENT'],
        ]);

        Livewire::test(\App\Livewire\CarePlan\CarePlanShow::class, ['carePlan' => $carePlan])
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

        $carePlan = CarePlan::create([
            'uuid' => (string) Str::uuid(),
            'person_id' => $this->person->id,
            'author_id' => $this->employee->id,
            'legal_entity_id' => $this->legalEntity->id,
            'period_start' => now()->format('Y-m-d'),
            'title' => 'Matching Conditions Plan',
            'status' => 'draft',
            'terms_of_service' => 'OUTPATIENT',
        ]);

        $programId = (string) Str::uuid();
        $medicationId = (string) Str::uuid();
        $this->seedMedicalProgram($programId, 'Outpatient program', [
            'providing_conditions_allowed' => ['OUTPATIENT'],
        ]);

        Livewire::test(\App\Livewire\CarePlan\CarePlanShow::class, ['carePlan' => $carePlan])
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

        $this->assertDatabaseHas('care_plan_activities', [
            'care_plan_id' => $carePlan->id,
            'product_reference' => $medicationId,
            'program' => $programId,
        ]);
    }

    public function test_blocks_activity_without_reason_reference_on_rehab_plan(): void
    {
        $this->actingAs($this->user);

        $carePlan = CarePlan::create([
            'uuid' => (string) Str::uuid(),
            'person_id' => $this->person->id,
            'author_id' => $this->employee->id,
            'legal_entity_id' => $this->legalEntity->id,
            'period_start' => now()->format('Y-m-d'),
            'title' => 'Rehab Plan',
            'status' => 'draft',
            'category' => 'CLASS 23',
            'terms_of_service' => 'OUTPATIENT',
        ]);

        Livewire::test(\App\Livewire\CarePlan\CarePlanShow::class, ['carePlan' => $carePlan])
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

        $carePlan = CarePlan::create([
            'uuid' => (string) Str::uuid(),
            'person_id' => $this->person->id,
            'author_id' => $this->employee->id,
            'legal_entity_id' => $this->legalEntity->id,
            'period_start' => now()->format('Y-m-d'),
            'title' => 'Rehab Plan OK',
            'status' => 'draft',
            'category' => 'CLASS 25',
            'terms_of_service' => 'OUTPATIENT',
        ]);

        $observationUuid = (string) Str::uuid();

        Livewire::test(\App\Livewire\CarePlan\CarePlanShow::class, ['carePlan' => $carePlan])
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
        $this->assertContains('Observation/' . $observationUuid, $activity->reasonReference ?? []);
    }

    public function test_activity_detail_card_renders_tv_fields(): void
    {
        $this->actingAs($this->user);

        $carePlan = CarePlan::create([
            'uuid' => (string) Str::uuid(),
            'person_id' => $this->person->id,
            'author_id' => $this->employee->id,
            'legal_entity_id' => $this->legalEntity->id,
            'period_start' => now()->format('Y-m-d'),
            'title' => 'Display Plan',
            'status' => 'active',
        ]);

        $activity = CarePlanActivity::create([
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

        Livewire::test(\App\Livewire\CarePlan\Activity\Show\CarePlanActivityShow::class, [
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
}
