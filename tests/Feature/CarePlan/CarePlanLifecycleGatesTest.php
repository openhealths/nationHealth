<?php

declare(strict_types=1);

namespace Tests\Feature\CarePlan;

use App\Models\CarePlan;
use App\Models\CarePlanActivity;
use App\Models\Employee\Employee;
use App\Models\LegalEntity;
use App\Models\MedicalEvents\Sql\Medications\MedicationRequestRequest;
use App\Models\MedicalEvents\Sql\ServiceRequestRequest;
use App\Models\Person\Person;
use App\Models\User;
use App\Services\MedicalEvents\CarePlanLifecycleGateService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class CarePlanLifecycleGatesTest extends TestCase
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
            'first_name' => 'Gate',
            'last_name' => 'Patient',
            'birth_date' => '1991-02-02',
            'gender' => 'MALE',
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
            'first_name' => 'Gate',
            'last_name' => 'Doctor',
            'tax_id' => '1122334455',
            'birth_date' => '1975-01-01',
            'gender' => 'MALE',
        ]);

        $this->user = User::create([
            'uuid' => (string) Str::uuid(),
            'email' => 'gate_' . Str::random(6) . '@example.com',
            'password' => Hash::make('password'),
            'party_id' => $party->id,
        ]);

        $this->employee = Employee::create([
            'uuid' => (string) Str::uuid(),
            'full_name' => 'Dr Gate',
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

    public function test_blocks_activity_cancel_when_open_medication_request_exists(): void
    {
        $this->actingAs($this->user);

        $carePlan = CarePlan::create([
            'uuid' => (string) Str::uuid(),
            'person_id' => $this->person->id,
            'author_id' => $this->employee->id,
            'legal_entity_id' => $this->legalEntity->id,
            'period_start' => now()->format('Y-m-d'),
            'title' => 'Gate Activity Cancel',
            'status' => 'active',
        ]);

        $activity = CarePlanActivity::create([
            'uuid' => (string) Str::uuid(),
            'care_plan_id' => $carePlan->id,
            'status' => 'scheduled',
            'kind' => 'medication_request',
            'author_id' => $this->employee->id,
        ]);

        MedicationRequestRequest::create([
            'uuid' => (string) Str::uuid(),
            'employee_id' => $this->employee->id,
            'person_id' => $this->person->id,
            'status' => 'active',
            'medication_id' => 'INN-101',
            'medication_qty' => 10,
            'intent' => 'order',
            'based_on_id' => $activity->id,
        ]);

        Livewire::test(\App\Livewire\CarePlan\CarePlanShow::class, ['carePlan' => $carePlan])
            ->call('openSignatureModal', 'cancel_activity', $activity->id)
            ->assertSet('showSignatureModal', false);

        // The block is flashed to the user; Livewire ages flash data at the end of its request,
        // so the reason itself is asserted at its source.
        $this->assertNotNull(
            app(CarePlanLifecycleGateService::class)
                ->activityStatusChangeBlockReason($activity->fresh(), 'cancel_activity')
        );
    }

    public function test_blocks_activity_complete_when_open_service_request_exists(): void
    {
        $this->actingAs($this->user);

        $carePlan = CarePlan::create([
            'uuid' => (string) Str::uuid(),
            'person_id' => $this->person->id,
            'author_id' => $this->employee->id,
            'legal_entity_id' => $this->legalEntity->id,
            'period_start' => now()->format('Y-m-d'),
            'title' => 'Gate Activity Complete',
            'status' => 'active',
        ]);

        $activity = CarePlanActivity::create([
            'uuid' => (string) Str::uuid(),
            'care_plan_id' => $carePlan->id,
            'status' => 'scheduled',
            'kind' => 'service_request',
            'author_id' => $this->employee->id,
        ]);

        ServiceRequestRequest::create([
            'uuid' => (string) Str::uuid(),
            'employee_id' => $this->employee->id,
            'person_id' => $this->person->id,
            'status' => 'in-progress',
            'service_id' => '59300-00',
            'quantity' => 1,
            'intent' => 'order',
            'based_on_id' => $activity->id,
            'priority' => 'routine',
        ]);

        Livewire::test(\App\Livewire\CarePlan\CarePlanShow::class, ['carePlan' => $carePlan])
            ->call('openSignatureModal', 'complete_activity', $activity->id)
            ->assertSet('showSignatureModal', false);

        $this->assertNotNull(
            app(CarePlanLifecycleGateService::class)
                ->activityStatusChangeBlockReason($activity->fresh(), 'complete_activity')
        );
    }

    public function test_blocks_plan_cancel_when_activity_is_scheduled(): void
    {
        $this->actingAs($this->user);

        $carePlan = CarePlan::create([
            'uuid' => (string) Str::uuid(),
            'person_id' => $this->person->id,
            'author_id' => $this->employee->id,
            'legal_entity_id' => $this->legalEntity->id,
            'period_start' => now()->format('Y-m-d'),
            'title' => 'Gate Plan Cancel',
            'status' => 'active',
        ]);

        CarePlanActivity::create([
            'uuid' => (string) Str::uuid(),
            'care_plan_id' => $carePlan->id,
            'status' => 'scheduled',
            'kind' => 'service_request',
            'author_id' => $this->employee->id,
        ]);

        Livewire::test(\App\Livewire\CarePlan\CarePlanShow::class, ['carePlan' => $carePlan->fresh('activities')])
            ->call('openSignatureModal', 'cancel')
            ->assertSet('showSignatureModal', false)
            ->assertDispatched('flashMessage');

        $this->assertNotNull(
            app(CarePlanLifecycleGateService::class)->planCancelBlockReason($carePlan->fresh('activities'))
        );
    }

    public function test_allows_activity_cancel_modal_when_no_open_documents(): void
    {
        $this->actingAs($this->user);

        $carePlan = CarePlan::create([
            'uuid' => (string) Str::uuid(),
            'person_id' => $this->person->id,
            'author_id' => $this->employee->id,
            'legal_entity_id' => $this->legalEntity->id,
            'period_start' => now()->format('Y-m-d'),
            'title' => 'Gate Activity Allow',
            'status' => 'active',
        ]);

        $activity = CarePlanActivity::create([
            'uuid' => (string) Str::uuid(),
            'care_plan_id' => $carePlan->id,
            'status' => 'scheduled',
            'kind' => 'medication_request',
            'author_id' => $this->employee->id,
        ]);

        MedicationRequestRequest::create([
            'uuid' => (string) Str::uuid(),
            'employee_id' => $this->employee->id,
            'person_id' => $this->person->id,
            'status' => 'rejected',
            'medication_id' => 'INN-101',
            'medication_qty' => 10,
            'intent' => 'order',
            'based_on_id' => $activity->id,
        ]);

        Livewire::test(\App\Livewire\CarePlan\CarePlanShow::class, ['carePlan' => $carePlan])
            ->call('openSignatureModal', 'cancel_activity', $activity->id)
            ->assertSet('showSignatureModal', true)
            ->assertSet('actionType', 'cancel_activity');
    }

    public function test_blocks_plan_complete_when_activity_is_in_progress_or_processed(): void
    {
        $this->actingAs($this->user);

        $carePlan = CarePlan::create([
            'uuid' => (string) Str::uuid(),
            'person_id' => $this->person->id,
            'author_id' => $this->employee->id,
            'legal_entity_id' => $this->legalEntity->id,
            'period_start' => now()->format('Y-m-d'),
            'title' => 'Gate Plan Complete',
            'status' => 'active',
        ]);

        CarePlanActivity::create([
            'uuid' => (string) Str::uuid(),
            'care_plan_id' => $carePlan->id,
            'status' => 'processed',
            'kind' => 'service_request',
            'author_id' => $this->employee->id,
        ]);

        Livewire::test(\App\Livewire\CarePlan\CarePlanShow::class, ['carePlan' => $carePlan->fresh('activities')])
            ->assertSee(__('forms.synchronise_with_eHealth'))
            ->call('openSignatureModal', 'complete')
            ->assertSet('showSignatureModal', false)
            ->assertDispatched('flashMessage');
    }

    public function test_allows_plan_complete_modal_when_all_activities_are_final_and_one_is_completed(): void
    {
        $this->actingAs($this->user);

        $carePlan = CarePlan::create([
            'uuid' => (string) Str::uuid(),
            'person_id' => $this->person->id,
            'author_id' => $this->employee->id,
            'legal_entity_id' => $this->legalEntity->id,
            'period_start' => now()->format('Y-m-d'),
            'title' => 'Gate Plan Complete Ready',
            'status' => 'active',
        ]);

        CarePlanActivity::create([
            'uuid' => (string) Str::uuid(),
            'care_plan_id' => $carePlan->id,
            'status' => 'completed',
            'kind' => 'service_request',
            'author_id' => $this->employee->id,
        ]);

        Livewire::test(\App\Livewire\CarePlan\CarePlanShow::class, ['carePlan' => $carePlan->fresh('activities')])
            ->call('openSignatureModal', 'complete')
            ->assertSet('showSignatureModal', true)
            ->assertSet('actionType', 'complete');
    }
}
