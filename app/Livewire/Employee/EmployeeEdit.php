<?php

declare(strict_types=1);

namespace App\Livewire\Employee;

use AllowDynamicProperties;
use App\Core\Arr;
use App\Livewire\Employee\Forms\EmployeeForm;
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

    #[Locked]
    public bool $isPersonalDataLocked = true;

    #[Locked]
    public bool $isDocumentDetailsEditable = false;

    public bool $isLockedDueToSignedRequest = false;

    public function mount(LegalEntity $legalEntity, Employee $employee): void
    {
        // MERGE STRATEGY: Instead of redirecting, check for an existing OPEN draft.
        $existingDraft = Repository::employee()->latestDraftForEmployee($employee);

        $this->loadDictionaries();
        $this->employee = $employee;
        $this->employeeId = $employee->id;

        $this->isPositionDataLocked = false;
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

        $this->applyImmutableFieldLocks($employee);
        $this->isPersonalDataLocked = true;
    }

    public function boot(): void
    {
        if ($this->employeeId) {
            $this->employee = Employee::findOrFail($this->employeeId);
        }
    }

    protected function applyEmployeeTypeBusinessRules(): void
    {
        // Preserve personal data already saved in the shared draft by the personal-data editor.
        $source = $this->localDraft() ?? $this->employee;
        $storedForm = new EmployeeForm($this, 'form');
        $storedForm->hydrate($source);
        $storedForm->fillPartyIdentity($this->employee->party);

        $this->form->party = $storedForm->party;
        $this->form->documents = $storedForm->documents;
        $this->form->existingPartyId = $storedForm->existingPartyId;

        parent::applyEmployeeTypeBusinessRules();
    }

    protected function localDraft(): ?EmployeeRequest
    {
        if ($this->employeeRequestId === null) {
            return null;
        }

        $draft = EmployeeRequest::with('revision')->find($this->employeeRequestId);

        return $draft?->isLocalDraft() && Repository::employee()->requestMatchesEmployee($draft, $this->employee)
            ? $draft
            : null;
    }

    protected function handleDraftPersistence(): EmployeeRequest
    {
        $preparedData = $this->prepareEmployeeUpdateData($this->employee);

        // The main speciality is retained when updating an existing position.
        if ($this->employee && $this->employee->id) {
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

        $employeeRequestData = Arr::only($preparedData, ['position', 'start_date', 'end_date', 'employee_type', 'division_id', 'email']);
        $employeeRequestData['user_id'] = $this->employee->party?->users()->first()?->id;
        $employeeRequestData['party_id'] = $this->employee->party->id;
        $employeeRequestData['employee_id'] = $this->employee->id;

        if ($existingRequest = $this->localDraft()) {
            $existingRequest->fill($employeeRequestData)->save();

            if ($existingRequest->revision) {
                $existingRequest->revision->update(['data' => $nestedDataForRevision]);
            } else {
                $this->saveRevisionForRequest($existingRequest, $nestedDataForRevision);
            }

            return $existingRequest;
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
