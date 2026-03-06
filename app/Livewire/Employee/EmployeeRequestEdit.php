<?php

declare(strict_types=1);

namespace App\Livewire\Employee;

use AllowDynamicProperties;
use App\Core\Arr;
use App\Models\Employee\EmployeeRequest;
use App\Models\LegalEntity;
use App\Repositories\Repository;
use Illuminate\View\View;

#[AllowDynamicProperties]
class EmployeeRequestEdit extends AbstractEmployeeFormManager
{
    public function mount(LegalEntity $legalEntity, EmployeeRequest $employee_request): void
    {
        $this->loadDictionaries();
        $this->loadDivisions($legalEntity);
        $this->employeeRequest = $employee_request;
        $this->employeeRequestId = $employee_request->id;

        $employeeName = $employee_request->party->fullName ?? ($employee_request->employee->party->fullName ?? '');
        $positionName = $this->dictionaries['POSITION'][$employee_request->position] ?? $employee_request->position;
        $this->pageTitle = __('forms.edit_employee_request') . ' "' . $positionName . '" - ' . $employeeName;

        $this->form->hydrate($this->employeeRequest);

        // LOCK LOGIC:
        if (!is_null($employee_request->uuid)) {
            // Signed Request: Lock Position fully
            $this->isPositionDataLocked = true;
            session()?->flash('info', __('forms.signed_request_can_edit_party_only'));
        } else {
            // Draft: Allow editing mutable fields, but LOCK immutable ones if linked to Employee
            $this->isPositionDataLocked = false;
            $this->isPersonalDataLocked = false;

            // This sets isCorePositionDataLocked = true AND isPartyDataPartiallyLocked = true
            // IF employee_id is present.
            $this->applyImmutableFieldLocks();
        }
    }

    public function boot(): void
    {
        if ($this->employeeRequestId) {
            $this->employeeRequest = EmployeeRequest::findOrFail($this->employeeRequestId);
        }
    }

    protected function handleDraftPersistence(): EmployeeRequest
    {
        $preparedData = $this->form->getPreparedData();
        $this->applyEmployeeTypeBusinessRules();
        $nestedDataForRevision = $this->mapRevisionData($preparedData);

        // ---Ensure employee_uuid is present if linked to an employee ---
        if ($this->employeeRequest->employeeId && $this->employeeRequest->employee) {
            $nestedDataForRevision['employee_uuid'] = $this->employeeRequest->employee->uuid;
        }

        // If it's a SIGNED request being corrected -> Create NEW Draft (Standard logic)
        if (!is_null($this->employeeRequest->uuid)) {
            $employeeRequestData = Arr::only($preparedData, ['position', 'start_date', 'end_date', 'employee_type', 'division_id', 'email']);
            $employeeRequestData['user_id'] = $this->employeeRequest->party?->users()->first()?->id;
            $employeeRequestData['party_id'] = $this->employeeRequest->party_id;
            $employeeRequestData['employee_id'] = $this->employeeRequest->employee_id;

            $newRequest = Repository::employee()->createEmployeeRequestDraft(
                $employeeRequestData,
                legalEntity(),
                $this->employeeRequest->employee
            );

            $this->saveRevisionForRequest($newRequest, $nestedDataForRevision);
            $this->employeeRequestId = $newRequest->id;

            return $newRequest;
        }

        // If it's a DRAFT -> Update existing
        $requestAttributes = Arr::only($preparedData, ['position', 'employee_type', 'start_date', 'end_date', 'division_id', 'email']);
        $this->employeeRequest->fill($requestAttributes)->save();

        if ($this->employeeRequest->revision) {
            $this->employeeRequest->revision->update(['data' => $nestedDataForRevision]);
        } else {
            $this->saveRevisionForRequest($this->employeeRequest, $nestedDataForRevision);
        }

        return $this->employeeRequest;
    }

    public function render(): View
    {
        return view('livewire.employee.employee-edit')->with('pageTitle', $this->pageTitle);
    }
}
