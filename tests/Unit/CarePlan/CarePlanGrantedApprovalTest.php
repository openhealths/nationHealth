<?php

declare(strict_types=1);

namespace Tests\Unit\CarePlan;

use App\Enums\Person\ApprovalStatus;
use App\Models\CarePlan;
use App\Models\Employee\Employee;
use App\Models\LegalEntity;
use App\Models\MedicalEvents\Sql\Approval;
use App\Models\MedicalEvents\Sql\Identifier;
use App\Models\Person\Person;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

class CarePlanGrantedApprovalTest extends TestCase
{
    use DatabaseTransactions;

    public function test_detects_active_approval_for_the_given_employee(): void
    {
        [$carePlan, $employee] = $this->makePlanWithEmployee();
        $this->grantApproval($carePlan, $employee, ApprovalStatus::ACTIVE->value);

        $this->assertTrue($carePlan->hasGrantedApprovalForEmployeeUuid($employee->uuid));
    }

    public function test_detects_approved_spelling_as_granted(): void
    {
        [$carePlan, $employee] = $this->makePlanWithEmployee();
        $this->grantApproval($carePlan, $employee, ApprovalStatus::APPROVED->value);

        $this->assertTrue($carePlan->hasGrantedApprovalForEmployeeUuid($employee->uuid));
    }

    public function test_ignores_approval_for_a_different_employee(): void
    {
        [$carePlan, $employee] = $this->makePlanWithEmployee();
        $other = Employee::create([
            'uuid' => (string) Str::uuid(),
            'full_name' => 'Other Doctor',
            'employee_type' => \App\Enums\User\Role::DOCTOR->value,
            'status' => \App\Enums\Status::APPROVED->value,
            'legal_entity_id' => $employee->legal_entity_id,
            'is_active' => true,
            'position' => 'Doctor',
            'start_date' => now()->format('Y-m-d'),
            'party_id' => $employee->party_id,
        ]);
        $this->grantApproval($carePlan, $other, ApprovalStatus::ACTIVE->value);

        $this->assertFalse($carePlan->hasGrantedApprovalForEmployeeUuid($employee->uuid));
    }

    public function test_ignores_unconfirmed_approval(): void
    {
        [$carePlan, $employee] = $this->makePlanWithEmployee();
        $this->grantApproval($carePlan, $employee, ApprovalStatus::NEW->value);

        $this->assertFalse($carePlan->hasGrantedApprovalForEmployeeUuid($employee->uuid));
    }

    /**
     * @return array{0: CarePlan, 1: Employee}
     */
    private function makePlanWithEmployee(): array
    {
        $typeId = \Illuminate\Support\Facades\DB::table('legal_entity_types')->where('name', 'PRIMARY_CARE')->value('id')
            ?? \Illuminate\Support\Facades\DB::table('legal_entity_types')->insertGetId(['name' => 'PRIMARY_CARE']);

        $legalEntity = LegalEntity::create([
            'uuid' => (string) Str::uuid(),
            'status' => 'ACTIVE',
            'sync_status' => 'COMPLETED',
            'legal_entity_type_id' => $typeId,
            'is_active' => true,
        ]);

        $party = \App\Models\Relations\Party::create([
            'uuid' => (string) Str::uuid(),
            'first_name' => 'Grant',
            'last_name' => 'Doctor',
            'tax_id' => '9988776655',
            'birth_date' => '1975-01-01',
            'gender' => 'MALE',
        ]);

        $employee = Employee::create([
            'uuid' => (string) Str::uuid(),
            'full_name' => 'Dr Grant',
            'employee_type' => \App\Enums\User\Role::DOCTOR->value,
            'status' => \App\Enums\Status::APPROVED->value,
            'legal_entity_id' => $legalEntity->id,
            'is_active' => true,
            'position' => 'Doctor',
            'start_date' => now()->format('Y-m-d'),
            'party_id' => $party->id,
        ]);

        $person = Person::create([
            'uuid' => (string) Str::uuid(),
            'first_name' => 'Grant',
            'last_name' => 'Patient',
            'birth_date' => '1991-02-02',
            'gender' => 'MALE',
            'patient_signed' => true,
            'process_disclosure_data_consent' => true,
        ]);

        $carePlan = CarePlan::create([
            'uuid' => (string) Str::uuid(),
            'person_id' => $person->id,
            'author_id' => $employee->id,
            'legal_entity_id' => $legalEntity->id,
            'period_start' => now()->format('Y-m-d'),
            'title' => 'Granted Approval Plan',
            'status' => 'new',
        ]);

        return [$carePlan, $employee];
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
