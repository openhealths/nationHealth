<?php

declare(strict_types=1);

namespace App\Livewire\Employee;

use AllowDynamicProperties;
use App\Core\Arr;
use App\Models\Employee\EmployeeRequest;
use App\Models\LegalEntity;
use App\Repositories\Repository;
use Illuminate\View\View;
use Illuminate\Validation\ValidationException;

#[AllowDynamicProperties]
class EmployeeRequestEdit extends AbstractEmployeeFormManager
{
    public function mount(LegalEntity $legalEntity, EmployeeRequest $employee_request): void
    {
        $this->employeeRequest = $employee_request;
        $this->employeeRequestId = $employee_request->id;

        if ($this->redirectIfEmployeeRequestAlreadyProcessed()) {
            return;
        }

        $this->loadDictionaries();
        $this->loadDivisions($legalEntity);

        $employeeName = $employee_request->party->fullName ?? ($employee_request->employee->party->fullName ?? '');
        $positionName = $this->dictionaries['POSITION'][$employee_request->position] ?? $employee_request->position;
        $this->pageTitle = __('forms.edit_employee_request') . ' "' . $positionName . '" - ' . $employeeName;

        $this->form->hydrate($this->employeeRequest);

        if ($employee = $employee_request->employee) {
            $this->applyImmutableFieldLocks($employee);
        }

        if (!is_null($employee_request->uuid)) {
            // Signed Request: Lock Position fully
            $this->isPositionDataLocked = true;
            session()?->flash('info', __('forms.signed_request_can_edit_party_only'));
        }
    }

    public function boot(): void
    {
        if ($this->employeeRequestId) {
            $this->employeeRequest = EmployeeRequest::findOrFail($this->employeeRequestId);
            $this->redirectIfEmployeeRequestAlreadyProcessed();
        }
    }

    protected function handleDraftPersistence(): EmployeeRequest
    {
        $employee = Repository::employee()->employeeForRequest($this->employeeRequest);
        if ($this->employeeRequest->employeeId && !$employee) {
            throw ValidationException::withMessages(['employeeRequestId' => __('employees.request_employee_mismatch')]);
        }

        $isAdditionalPosition = !$this->employeeRequest->employeeId
            && $this->employeeRequest->partyId
            && Repository::employee()->hasPartyInLegalEntity($this->employeeRequest->partyId, legalEntity()->id);

        if ($isAdditionalPosition) {
            $selectedUser = $this->resolvePositionEmailUser($this->employeeRequest->party);
        }

        $preparedData = $employee ? $this->prepareEmployeeUpdateData($employee) : $this->form->getPreparedData();
        $nestedDataForRevision = $this->mapRevisionData($preparedData);

        if ($employee) {
            $nestedDataForRevision['employee_uuid'] = $employee->uuid;
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
                $employee
            );

            $this->saveRevisionForRequest($newRequest, $nestedDataForRevision);
            $this->employeeRequestId = $newRequest->id;

            return $newRequest;
        }

        // If it's a DRAFT -> Update existing
        $requestAttributes = Arr::only($preparedData, ['position', 'employee_type', 'start_date', 'end_date', 'division_id', 'email']);
        if ($isAdditionalPosition) {
            $requestAttributes['user_id'] = $selectedUser?->id;
        }

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
