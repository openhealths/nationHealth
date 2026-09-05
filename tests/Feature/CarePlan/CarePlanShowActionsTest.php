<?php

declare(strict_types=1);

namespace Tests\Feature\CarePlan;

use App\Enums\Person\ApprovalStatus;
use App\Livewire\CarePlan\CarePlanShow;
use App\Models\CarePlan;
use App\Models\Employee\Employee;
use App\Models\LegalEntity;
use App\Models\MedicalEvents\Sql\Approval;
use App\Models\MedicalEvents\Sql\Identifier;
use App\Models\Person\Person;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class CarePlanShowActionsTest extends TestCase
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
            'first_name' => 'Action',
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
            'first_name' => 'Action',
            'last_name' => 'Doctor',
            'tax_id' => '1122334455',
            'birth_date' => '1975-01-01',
            'gender' => 'MALE',
        ]);

        $this->user = User::create([
            'uuid' => (string) Str::uuid(),
            'email' => 'action_'.Str::random(6).'@example.com',
            'password' => Hash::make('password'),
            'party_id' => $party->id,
        ]);

        $this->employee = Employee::create([
            'uuid' => (string) Str::uuid(),
            'full_name' => 'Dr Action',
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

    public function test_new_plan_without_approval_shows_activate_and_hides_lifecycle_buttons(): void
    {
        $this->actingAs($this->user);

        $carePlan = $this->makeSignedNewPlan();

        $component = Livewire::test(CarePlanShow::class, ['carePlan' => $carePlan]);

        $this->assertTrue($component->instance()->canRequestPatientApproval);
        $this->assertFalse($component->instance()->canChangePlanLifecycle);
        $component
            ->assertSee(__('care-plan.activate_plan_patient_approval'))
            ->assertDontSee(__('care-plan.cancel_care_plan'))
            ->assertDontSee(__('care-plan.complete_care_plan'));
    }

    public function test_new_plan_with_active_approval_for_current_doctor_shows_cancel_and_complete(): void
    {
        $this->actingAs($this->user);

        $carePlan = $this->makeSignedNewPlan();
        $this->grantApproval($carePlan, $this->employee, ApprovalStatus::ACTIVE->value);

        $component = Livewire::test(CarePlanShow::class, ['carePlan' => $carePlan->fresh()]);

        $this->assertFalse($component->instance()->canRequestPatientApproval);
        $this->assertTrue($component->instance()->canChangePlanLifecycle);
        $component
            ->assertDontSee(__('care-plan.activate_plan_patient_approval'))
            ->assertSee(__('care-plan.cancel_care_plan'))
            ->assertSee(__('care-plan.complete_care_plan'));
    }

    public function test_cancelled_plan_hides_lifecycle_buttons_and_blocks_cancel(): void
    {
        $this->actingAs($this->user);

        $carePlan = $this->makeSignedNewPlan();
        $carePlan->update(['status' => 'cancelled']);
        $this->grantApproval($carePlan, $this->employee, ApprovalStatus::ACTIVE->value);

        $component = Livewire::test(CarePlanShow::class, ['carePlan' => $carePlan->fresh()]);

        $this->assertTrue($component->instance()->isTerminalCarePlan);
        $this->assertFalse($component->instance()->canChangePlanLifecycle);
        $this->assertFalse($component->instance()->canRequestPatientApproval);
        $component
            ->assertDontSee(__('care-plan.cancel_care_plan'))
            ->assertDontSee(__('care-plan.complete_care_plan'))
            ->assertDontSee(__('care-plan.activate_plan_patient_approval'))
            ->assertSee(__('care-plan.status.cancelled'))
            ->assertSee(__('forms.synchronise_with_eHealth'))
            ->call('openSignatureModal', 'cancel')
            ->assertSet('showSignatureModal', false)
            ->assertDispatched('flashMessage');
    }

    public function test_show_page_offers_ehealth_sync_next_to_new_activity_actions(): void
    {
        $this->actingAs($this->user);

        Livewire::test(CarePlanShow::class, ['carePlan' => $this->makeSignedNewPlan()])
            ->assertSee(__('forms.synchronise_with_eHealth'))
            ->assertSee(__('care-plan.new_prescription'));
    }

    public function test_cancel_sign_without_kep_flashes_an_error_the_doctor_can_see(): void
    {
        $this->actingAs($this->user);

        $carePlan = $this->makeSignedNewPlan();
        $this->grantApproval($carePlan, $this->employee, ApprovalStatus::ACTIVE->value);

        Livewire::test(CarePlanShow::class, ['carePlan' => $carePlan->fresh()])
            ->call('openSignatureModal', 'cancel')
            ->set('statusReason', 'typo')
            ->call('sign')
            ->assertDispatched('flashMessage')
            ->assertSet('showSignatureModal', true);
    }

    private function makeSignedNewPlan(): CarePlan
    {
        return CarePlan::create([
            'uuid' => (string) Str::uuid(),
            'person_id' => $this->person->id,
            'author_id' => $this->employee->id,
            'legal_entity_id' => $this->legalEntity->id,
            'period_start' => now()->format('Y-m-d'),
            'title' => 'Show Actions Plan',
            'status' => 'new',
        ]);
    }

    private function grantApproval(CarePlan $carePlan, Employee $employee, string $status): void
    {
        $identifier = Identifier::create(['value' => $employee->uuid]);

        Approval::create([
            'uuid' => (string) Str::uuid(),
            'approvable_type' => CarePlan::class,
            'approvable_id' => $carePlan->id,
            'granted_to_id' => $identifier->id,
            'granted_to_type' => 'employee',
            'status' => $status,
            'is_verified' => true,
        ]);
    }
}
