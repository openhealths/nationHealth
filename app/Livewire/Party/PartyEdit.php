<?php

declare(strict_types=1);

namespace App\Livewire\Party;

use AllowDynamicProperties;
use App\Enums\Status;
use App\Livewire\Employee\AbstractEmployeeFormManager;
use App\Models\Employee\EmployeeRequest;
use App\Models\LegalEntity;
use App\Models\Relations\Party;
use App\Repositories\Repository;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;

#[AllowDynamicProperties]
class PartyEdit extends AbstractEmployeeFormManager
{
    #[Locked]
    public ?int $partyId = null;

    #[Locked]
    public int $employeeId;

    public function mount(LegalEntity $legalEntity, Party $party): void
    {
        $this->loadDictionaries();
        $this->loadDivisions($legalEntity);
        $this->party = $party;
        $this->partyId = $party->id;
        $this->pageTitle = __('forms.edit_personal_data') . ' - ' . ($party->fullName ?? '');

        $employee = $party->employees()
            ->where('legal_entity_id', $legalEntity->id)
            ->whereStatus(Status::APPROVED)
            ->latest('start_date')
            ->latest('id')
            ->firstOrFail();
        $this->employeeId = $employee->id;
        $existingDraft = Repository::employee()->latestDraftForEmployee($employee);

        if ($existingDraft) {
            $this->employeeRequestId = $existingDraft->id;
            $this->employeeRequest = $existingDraft;
            $this->form->hydrate($existingDraft);
            session()->flash('info', __('forms.draft_loaded_automatically'));
        } else {
            $this->form->hydrate($employee);
        }

        $this->applyImmutableFieldLocks($employee);
        $this->isPositionDataLocked = true;
    }

    public function boot(): void
    {
        if ($this->partyId) {
            $this->party = Party::findOrFail($this->partyId);
        }
    }

    #[Computed]
    public function partyPositions(): Collection
    {
        // Filter employees to show only positions within the current Legal Entity
        return $this->party->employees()
            ->where('legal_entity_id', legalEntity()->id)
            ->with('division')
            ->get();
    }

    /**
     * Creates a draft that contains updated personal data (Party/Documents)
     * AND unchanged position data (from blocked fields).
     */
    protected function handleDraftPersistence(): EmployeeRequest
    {
        $employee = $this->party->employees()
            ->where('legal_entity_id', legalEntity()->id)
            ->whereStatus(Status::APPROVED)
            ->findOrFail($this->employeeId);

        $preparedData = $this->prepareEmployeeUpdateData($employee);
        $nestedDataForRevision = $this->mapRevisionData($preparedData);
        $nestedDataForRevision['employee_uuid'] = $employee->uuid;

        $employeeRequestData = [
            'user_id' => $employee->user_id,
            'party_id' => $this->party->id,
            'employee_id' => $employee->id,
            'position' => $preparedData['position'],
            'employee_type' => $preparedData['employee_type'],
            'start_date' => $preparedData['start_date'],
            'division_id' => $preparedData['division_id'] ?? $employee->divisionId,
            'email' => $preparedData['email'],
        ];

        if ($this->employeeRequestId) {
            $existingRequest = EmployeeRequest::find($this->employeeRequestId);
            if ($existingRequest?->isLocalDraft() && Repository::employee()->requestMatchesEmployee($existingRequest, $employee)) {
                $existingRequest->fill($employeeRequestData)->save();

                if ($existingRequest->revision) {
                    $existingRequest->revision->update(['data' => $nestedDataForRevision]);
                } else {
                    $this->saveRevisionForRequest($existingRequest, $nestedDataForRevision);
                }

                return $existingRequest;
            }
        }

        $newRequest = Repository::employee()->createEmployeeRequestDraft($employeeRequestData, legalEntity(), $employee);
        $this->saveRevisionForRequest($newRequest, $nestedDataForRevision);

        return $newRequest;
    }

    public function render(): View
    {
        return view('livewire.party.party-edit')->with('pageTitle', $this->pageTitle);
    }
}
