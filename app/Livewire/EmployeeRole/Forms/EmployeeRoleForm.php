<?php

declare(strict_types=1);

namespace App\Livewire\EmployeeRole\Forms;

use App\Enums\HealthcareService\Status as HealthcareServiceStatus;
use App\Enums\Status;
use App\Enums\User\Role;
use App\Models\Employee\Employee;
use App\Models\EmployeeRole;
use App\Models\HealthcareService;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Form;

class EmployeeRoleForm extends Form
{
    public string $employeeId;

    public string $healthcareServiceId;

    public function rules(): array
    {
        return [
            'employeeId' => [
                'required',
                'uuid',
                Rule::exists('employees', 'uuid')
                    ->where('legal_entity_id', legalEntity()->id)
                    ->whereIn('employee_type', [
                        Role::DOCTOR->value,
                        Role::SPECIALIST->value,
                        Role::ASSISTANT->value,
                        Role::LABORANT->value
                    ])
                    ->where('status', Status::APPROVED->value)
                    ->where('is_active', true)
            ],
            'healthcareServiceId' => [
                'required',
                'uuid',
                Rule::exists('healthcare_services', 'uuid')
                    ->where('legal_entity_id', legalEntity()->id)
                    ->where('status', HealthcareServiceStatus::ACTIVE->value)
                    ->where('is_active', true)
            ]
        ];
    }

    public function validate($rules = null, $messages = [], $attributes = []): array
    {
        $validated = parent::validate($rules, $messages, $attributes);

        $employee = Employee::whereUuid($this->employeeId)
            ->with('specialities:speciality,speciality_officio,specialityable_id')
            ->select('id')
            ->firstOrFail();
        $healthcareService = HealthcareService::whereUuid($this->healthcareServiceId)
            ->select(['id', 'speciality_type'])
            ->firstOrFail();

        $this->validateEmployeeSpeciality($employee, $healthcareService);
        $this->validateConstraints($employee, $healthcareService);

        return $validated;
    }

    protected function validationAttributes(): array
    {
        return [
            'employeeId' => __('employee-roles.employeeId'),
            'healthcareServiceId' => __('employee-roles.healthcareServiceId')
        ];
    }

    /**
     * Check that the employee's officio (primary) speciality matches the healthcare service speciality.
     *
     * @param  Employee  $employee
     * @param  HealthcareService  $healthcareService
     * @return void
     */
    protected function validateEmployeeSpeciality(Employee $employee, HealthcareService $healthcareService): void
    {
        $officioSpeciality = $employee->specialities->firstWhere('specialityOfficio', true)?->speciality;

        if ($officioSpeciality !== $healthcareService->specialityType) {
            throw ValidationException::withMessages([
                'specialization' => __('validation.attributes.employeeRole.constraint.specialityMismatch')
            ]);
        }
    }

    /**
     * It can be only one active employee_role for the single employee and healthcare service
     *
     * @param  Employee  $employee
     * @param  HealthcareService  $healthcareService
     * @return void
     */
    protected function validateConstraints(Employee $employee, HealthcareService $healthcareService): void
    {
        $exists = EmployeeRole::whereEmployeeId($employee->id)
            ->whereHealthcareServiceId($healthcareService->id)
            ->whereStatus(Status::ACTIVE)
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'employee_role' => __('validation.attributes.employeeRole.constraint.duplicateActiveRole')
            ]);
        }
    }
}
