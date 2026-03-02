<?php

declare(strict_types=1);

namespace App\Livewire\Employee;

use AllowDynamicProperties;
use App\Core\Arr;
use App\Models\Employee\EmployeeRequest;
use App\Models\LegalEntity;
use App\Models\Relations\Party;
use App\Repositories\Repository;
use Illuminate\View\View;
use Livewire\Attributes\Locked;

#[AllowDynamicProperties]
class EmployeePositionAdd extends AbstractEmployeeFormManager
{
    #[Locked]
    public ?int $partyId = null;
    protected ?Party $party = null;

    public function mount(LegalEntity $legalEntity, Party $party): void
    {
        $this->loadDictionaries();
        $this->loadDivisions($legalEntity);
        $this->isPersonalDataLocked = true;
        $party->loadMissing('users');
        $this->party = $party;
        $this->partyId = $party->id;
        $this->form->hydrate($this->party);
        $this->form->resetPositionFields();
        $this->pageTitle = __('forms.add_position') . ' - ' . ($party->fullName ?? '');
        $users = $party->users()->oldest()->get();
        $this->partyUsers = $users;
        $this->formEmail = $users->first()?->email;
        $this->form->party['email'] = $this->formEmail;

    }

    public function boot(): void
    {
        if ($this->partyId) {
            $this->party = Party::findOrFail($this->partyId);
        }
    }

    /**
     * Implements the draft persistence logic for adding a new position.
     * It updates the draft if it already exists for this session,
     * or creates a new one if it's the first save.
     */
    protected function handleDraftPersistence(): EmployeeRequest
    {
        $preparedData = $this->form->getPreparedData();
        $this->applyEmployeeTypeBusinessRules();
        $nestedDataForRevision = $this->mapRevisionData($preparedData);

        $employeeRequestData = Arr::only($preparedData, [
            'position', 'start_date', 'end_date', 'employee_type', 'division_id', 'email'
        ]);

        $selectedUser = $this->partyUsers->firstWhere('email', $this->formEmail);
        $employeeRequestData['user_id'] = $selectedUser?->id;

        $employeeRequestData['party_id'] = $this->party->id;

        if ($this->employeeRequestId) {
            $existingRequest = EmployeeRequest::find($this->employeeRequestId);
            if ($existingRequest && is_null($existingRequest->uuid)) {
                $existingRequest->fill($employeeRequestData)->save();
                $existingRequest->revision?->update(['data' => $nestedDataForRevision]);

                return $existingRequest;
            }
        }

        // If no draft exists, create a new one using the prepared data
        $newRequest = Repository::employee()->createEmployeeRequestDraft($employeeRequestData, legalEntity());
        $this->saveRevisionForRequest($newRequest, $nestedDataForRevision);

        return $newRequest;
    }

    public function render(): View
    {
        return view('livewire.employee.employee-position-add')->with('pageTitle', $this->pageTitle);
    }

    /**
     * Finds and returns the existing draft request if its ID is known.
     * Returns null only if this is the very first save action for a new form.
     */
    protected function getEmployeeRequestForSave(): ?EmployeeRequest
    {
        if (!empty($this->employeeRequestId)) {
            return EmployeeRequest::find($this->employeeRequestId);
        }

        return null;
    }
}
