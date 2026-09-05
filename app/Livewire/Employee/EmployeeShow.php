<?php

declare(strict_types=1);

namespace App\Livewire\Employee;

use App\Classes\eHealth\EHealth;
use App\Enums\Status;
use App\Enums\User\Role;
use App\Models\Employee\Employee;
use App\Models\LegalEntity;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Illuminate\Support\Collection;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use Throwable;

class EmployeeShow extends EmployeeComponent
{
    protected Employee $employee;

    #[Locked]
    public ?int $employeeId = null;

    public bool $isPersonalDataLocked = true;
    public bool $isPositionDataLocked = true;
    public bool $isPartyDataPartiallyLocked = false;
    public ?Collection $partyUsers = null;

    public bool $showDeactivateModal = false;
    public ?int $employeeIdToDeactivate = null;
    public ?string $employeeToDeactivateName = null;
    public bool $isDoctorToDeactivate = false;
    public string $deactivationEndDate = '';
    public string $deactivationStatus = 'STOPPED';

    public function mount(LegalEntity $legalEntity, Employee $employee): void
    {
        $this->loadDictionaries();
        $this->loadDivisions($legalEntity);
        $this->employee = $employee;
        $this->employeeId = $employee->id;
        $this->form->hydrate($this->employee);
    }

    public function boot(): void
    {
        if ($this->employeeId) {
            $this->employee = Employee::findOrFail($this->employeeId);
        }
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws Throwable
     * @throws NotFoundExceptionInterface
     */
    public function sync(): void
    {
        // Call the parent method using the currently loaded employee
        $success = $this->syncEmployeeData($this->employee);

        if ($success) {
            // Specific logic for the "Show" page:
            // We need to re-hydrate the form so the input fields show the new data immediately
            $this->employee->refresh();
            $this->form->hydrate($this->employee);
        }
    }

    public function showModalDeactivate(int $id): void
    {
        $employee = Employee::find($id);

        if ($employee) {
            $this->authorize('deactivate', $employee);
            $this->employeeIdToDeactivate = $id;
            $this->employeeToDeactivateName = $employee->full_name
                ?? ($employee->last_name . ' ' . $employee->first_name);
            $this->isDoctorToDeactivate = ($employee->employeeType ?? '') === Role::DOCTOR->value;
        }

        $this->deactivationStatus = Status::STOPPED->value;
        $startDateStr = isset($employee) ? toIsoDate($employee->start_date) : null;
        $todayStr = now('Europe/Kyiv')->format('Y-m-d');
        $this->deactivationEndDate = ($startDateStr && $todayStr < $startDateStr) ? $startDateStr : $todayStr;
        $this->showDeactivateModal = true;
    }

    public function updatedDeactivationStatus(string $value): void
    {
        if ($value === Status::ENTERED_IN_ERROR->value) {
            $this->deactivationEndDate = '';
        }
    }

    public function deactivate(): void
    {
        $employee = Employee::find($this->employeeIdToDeactivate);

        if (!$employee) {
            $this->showDeactivateModal = false;

            return;
        }

        $this->authorize('deactivate', $employee);

        $todayStr = now('Europe/Kyiv')->format('Y-m-d');
        $startDateStr = toIsoDate($employee->start_date);
        $status = in_array($this->deactivationStatus, [Status::STOPPED->value, Status::ENTERED_IN_ERROR->value], true)
            ? $this->deactivationStatus
            : Status::STOPPED->value;
        $formattedEndDate = null;

        if ($status === Status::STOPPED->value) {
            $formattedEndDate = toIsoDate(trim($this->deactivationEndDate) !== '' ? $this->deactivationEndDate : $todayStr) ?? $todayStr;

            if ($startDateStr && $formattedEndDate < $startDateStr) {
                $this->dispatch('flashMessage', [
                    'message' => __('employees.deactivation_end_date_before_start'),
                    'type' => 'error',
                ]);

                return;
            }

            if ($formattedEndDate > $todayStr) {
                $this->dispatch('flashMessage', [
                    'message' => __('employees.deactivation_end_date_in_future'),
                    'type' => 'error',
                ]);

                return;
            }
        }

        EHealth::employee()->deactivate($employee->uuid, $formattedEndDate, $status);
        $employee->update([
            'status' => $status,
            'end_date' => $formattedEndDate,
            'is_active' => false,
        ]);

        $this->showDeactivateModal = false;
        $this->dispatch('flashMessage', ['message' => __('employees.dismissalSuccess'), 'type' => 'success']);
        $this->redirectRoute('employee.index', [legalEntity()], navigate: true);
    }

    public function render(): View
    {
        $partyExistingPositions = null;
        if ($this->employee->party) {
            $this->employee->party->loadMissing(['employees.division', 'employeeRequests.division']);
            $partyExistingPositions = $this->employee->party->employees->merge($this->employee->party->employeeRequests);
        }

        return view('livewire.employee.employee-show', [
            'employee' => $this->employee,
            'partyExistingPositions' => $partyExistingPositions
        ]);
    }
}
