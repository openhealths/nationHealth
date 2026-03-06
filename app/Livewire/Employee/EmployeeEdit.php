<?php

declare(strict_types=1);

namespace App\Livewire\Employee;

use AllowDynamicProperties;
use App\Core\Arr;
use App\Models\Employee\Employee;
use App\Models\Employee\EmployeeRequest;
use App\Models\LegalEntity;
use App\Repositories\Repository;
use Illuminate\View\View;
use Livewire\Attributes\Locked;

#[AllowDynamicProperties]
class EmployeeEdit extends AbstractEmployeeFormManager
{
    #[Locked]
    public ?int $employeeId = null;
    public bool $showSignatureModal = false;
    public bool $isLockedDueToSignedRequest = false;

    public function mount(LegalEntity $legalEntity, Employee $employee): void
    {
        // MERGE STRATEGY: Instead of redirecting, check for an existing OPEN draft.
        $existingDraft = EmployeeRequest::where('employee_id', $employee->id)
            ->whereNull('uuid') // Only drafts (not signed/approved)
            ->whereNull('applied_at')
            ->latest()
            ->first();

        $this->loadDictionaries();
        $this->employee = $employee;
        $this->employeeId = $employee->id;
        $this->isPersonalDataLocked = true;
        $this->isPositionDataLocked = true;
        $this->loadDivisions($legalEntity);

        $positionName = $this->dictionaries['POSITION'][$employee->position] ?? $employee->position;
        $this->pageTitle = __('forms.edit_employee') . ' "' . $positionName . '" - ' . ($employee->party->fullName ?? '');

        if ($existingDraft) {
            // Found a draft! Load it so we merge changes.
            $this->employeeRequestId = $existingDraft->id;
            $this->employeeRequest = $existingDraft;
            $this->form->hydrate($existingDraft);

            // Optionally, show a message that a draft exists
            session()?->flash('info', __('forms.draft_loaded_automatically'));
        } else {
            // No draft, load fresh from Employee
            $this->form->hydrate($this->employee);
        }
    }

    public function boot(): void
    {
        if ($this->employeeId) {
            $this->employee = Employee::findOrFail($this->employeeId);
        }
    }

    protected function handleDraftPersistence(): EmployeeRequest
    {
        $preparedData = $this->form->getPreparedData();
        $this->applyEmployeeTypeBusinessRules();
        $nestedDataForRevision = $this->mapRevisionData($preparedData);
        $nestedDataForRevision['employee_uuid'] = $this->employee->uuid;

        // Since we check for draft in mount(), $this->employeeRequestId is likely set if a draft existed.
        // We reuse the logic to update it.

        $employeeRequestData = Arr::only($preparedData, ['position', 'start_date', 'end_date', 'employee_type', 'division_id', 'email']);
        $employeeRequestData['user_id'] = $this->employee->party?->users()->first()?->id;
        $employeeRequestData['party_id'] = $this->employee->party->id;
        $employeeRequestData['employee_id'] = $this->employee->id;

        if ($this->employeeRequestId) {
            $existingRequest = EmployeeRequest::find($this->employeeRequestId);

            if ($existingRequest && is_null($existingRequest->uuid)) {
                $existingRequest->fill($employeeRequestData)->save();

                // Merge revision data carefully (though mapRevisionData usually has everything needed from form)
                // Since form was hydrated from draft, getPreparedData contains previous draft values + new edits.
                $existingRequest->revision?->update(['data' => $nestedDataForRevision]);

                return $existingRequest;
            }
        }

        // Create new if really nothing exists
        $newRequest = Repository::employee()->createEmployeeRequestDraft(
            $employeeRequestData,
            legalEntity(),
            $this->employee
        );

        $this->saveRevisionForRequest($newRequest, $nestedDataForRevision);

        return $newRequest;
    }

    /**
     * The render method. It doesn't need to pass any data, because the template
     * is already bound to the component's public properties (like $this->form).
     */
    public function render(): View
    {
        return view('livewire.employee.employee-edit')->with('pageTitle', $this->pageTitle);
    }
}
