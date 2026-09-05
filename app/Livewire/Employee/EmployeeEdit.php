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

        // Check if the Party of this employee holds the Owner position
        $isOwnerParty = $employee->party->employees()
            ->where('employee_type', \App\Enums\User\Role::OWNER->value)
            ->exists();

        $this->isPersonalDataLocked = $isOwnerParty;
        // Keep division / professional blocks editable; lock only immutable core fields via applyImmutableFieldLocks().
        $this->isPositionDataLocked = false;
        $this->loadDivisions($legalEntity);
        $this->applyImmutableFieldLocks();

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

        // Backend enforcement: immutable fields per 3.23.1.7
        if ($this->employee && $this->employee->id) {
            $preparedData['position'] = $this->employee->position;
            $preparedData['employee_type'] = $this->employee->employeeType;
            if ($this->employee->startDate) {
                $preparedData['start_date'] = toIsoDate($this->employee->startDate);
            }

            $preparedData['tax_id'] = $this->employee->party->taxId;
            $preparedData['no_tax_id'] = $this->employee->party->noTaxId;
            if ($this->employee->party->birthDate) {
                $preparedData['birth_date'] = toIsoDate($this->employee->party->birthDate);
            }

            $originalPrimarySpeciality = $this->employee->specialities()
                ->where('speciality_officio', true)
                ->first();

            if ($originalPrimarySpeciality) {
                $submittedSpecialities = $preparedData['doctor']['specialities'] ?? [];
                $filteredSpecialities = array_filter(
                    $submittedSpecialities,
                    fn ($spec) => empty($spec['speciality_officio'])
                );

                // getPreparedData() already returns snake_case — never reintroduce camelCase keys.
                $attestationDate = toIsoDate($originalPrimarySpeciality->attestationDate);
                $validToDate = toIsoDate($originalPrimarySpeciality->validToDate);

                $primarySpecData = array_filter([
                    'speciality' => $originalPrimarySpeciality->speciality,
                    'speciality_officio' => true,
                    'attestation_name' => $originalPrimarySpeciality->attestationName,
                    'attestation_date' => $attestationDate,
                    'certificate_number' => $originalPrimarySpeciality->certificateNumber,
                    'level' => $originalPrimarySpeciality->level,
                    'qualification_type' => $originalPrimarySpeciality->qualificationType,
                    'valid_to_date' => $validToDate,
                ], static fn ($value) => $value !== null && $value !== '');

                $filteredSpecialities[] = $primarySpecData;
                $preparedData['doctor']['specialities'] = array_values($filteredSpecialities);
            }
        }

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
