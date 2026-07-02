<?php

declare(strict_types=1);

namespace App\Livewire\EmployeeRole\Forms;

use App\Enums\Status;
use App\Models\Employee\Employee;
use App\Models\EmployeeRole;
use App\Models\HealthcareService;
use Illuminate\Validation\ValidationException;
use Livewire\Form;

class EmployeeRoleForm extends Form
{
    public string $employeeId;

    public string $healthcareServiceId;

    public function rules(): array
    {
        return [
            'employeeId' => ['required', 'uuid', 'exists:employees,uuid'],
            'healthcareServiceId' => ['required', 'uuid', 'exists:healthcare_services,uuid']
        ];
    }

    public function validate($rules = null, $messages = [], $attributes = []): array
    {
        $validated = parent::validate($rules, $messages, $attributes);

        $employee = Employee::whereUuid($this->employeeId)
            ->with('specialities:speciality,specialityable_id')
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
     * Check that employee has the same specializations as the healthcare service
     *
     * @param  Employee  $employee
     * @param  HealthcareService  $healthcareService
     * @return void
     */
    protected function validateEmployeeSpeciality(Employee $employee, HealthcareService $healthcareService): void
    {
        if (($healthcareService->specialityType ?? '') === '') {
            return;
        }

        $specialities = $employee->specialities->pluck('speciality')->toArray();

        if (!in_array($healthcareService->specialityType, $specialities, true)) {
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
