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

    public function mount(LegalEntity $legalEntity, Party $party): void
    {
        $this->loadDictionaries();
        $this->loadDivisions($legalEntity);
        $this->party = $party;
        $this->partyId = $party->id;
        $this->pageTitle = __('forms.edit_personal_data') . ' - ' . ($party->fullName ?? '');

        // Fetch the latest employee record strictly within the current Legal Entity
        $employee = $party->employees()
            ->where('legal_entity_id', $legalEntity->id)
            ->latest('start_date')
            ->first();

        // MERGE STRATEGY
        $existingDraft = null;
        if ($employee) {
            $existingDraft = EmployeeRequest::where('employee_id', $employee->id)
                ->whereNull('uuid')
                ->whereNull('applied_at')
                ->latest()
                ->first();
        }

        if ($existingDraft) {
            $this->employeeRequestId = $existingDraft->id;
            $this->employeeRequest = $existingDraft;
            $this->form->hydrate($existingDraft);
            session()->flash('info', __('forms.draft_loaded_automatically'));
        } else {
            // Hydrate from Employee if exists (within this LE), otherwise Party
            $this->form->hydrate($employee ?? $party);
        }

        $this->isPartyDataPartiallyLocked = true;
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
            ->where('status', '!=', Status::DISMISSED->value)
            ->latest('start_date')
            ->firstOrFail();

        $preparedData = $this->form->getPreparedData();
        $nestedDataForRevision = $this->mapRevisionData($preparedData);
        $nestedDataForRevision['employee_uuid'] = $employee->uuid;

        // Data for Request Model (System fields)
        // Since PartyEdit view blocks position fields, preparedData has them from hydrate (which got them from Draft or Employee)
        $employeeRequestData = [
            'user_id' => $employee->user_id,
            'party_id' => $this->party->id,
            'employee_id' => $employee->id,
            'position' => $preparedData['position'] ?? $employee->position, // Use form data if present (from merged draft)
            'employee_type' => $preparedData['employee_type'] ?? $employee->employee_type,
            'start_date' => $preparedData['start_date'] ?? $employee->start_date?->format('Y-m-d'),
            'division_id' => $preparedData['division_id'] ?? $employee->division_id,
            'email' => $preparedData['email']
        ];

        if ($this->employeeRequestId) {
            $existingRequest = EmployeeRequest::find($this->employeeRequestId);
            if ($existingRequest && is_null($existingRequest->uuid)) {
                $existingRequest->fill($employeeRequestData)->save();
                $existingRequest->revision?->update(['data' => $nestedDataForRevision]);

                return $existingRequest;
            }
        }

        $newRequest = Repository::employee()->createEmployeeRequestDraft($employeeRequestData, legalEntity());
        $this->saveRevisionForRequest($newRequest, $nestedDataForRevision);

        return $newRequest;
    }

    public function render(): View
    {
        return view('livewire.party.party-edit')->with('pageTitle', $this->pageTitle);
    }
}
