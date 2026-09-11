<?php

declare(strict_types=1);

namespace App\Livewire\Person\Records;

use App\Repositories\MedicalEvents\MedicationRequestRepository;
use Illuminate\Contracts\View\View;

class PatientMedicationRequests extends BasePatientComponent
{
    /** @var list<array<string, mixed>> */
    public array $medicationRequests = [];

    public string $filterStatus = '';

    public string $filterStartedAtFrom = '';

    public string $filterStartedAtTo = '';

    public string $filterEndedAtFrom = '';

    public string $filterEndedAtTo = '';

    public string $filterRequestNumber = '';

    public string $filterMedication = '';

    public string $filterInteractionId = '';

    public string $filterCarePlanId = '';

    public string $filterDoctor = '';

    public string $filterEpisodeId = '';

    public string $filterLegalEntity = '';

    public string $filterMedicalProgram = '';

    public string $filterCreatedAtRange = '';

    public string $filterDispenseAvailableFromRange = '';

    public string $filterDispenseAvailableToRange = '';

    public bool $showAdditionalParams = false;

    protected function initializeComponent(): void
    {
        $this->loadMedicationRequests();
    }

    public function loadMedicationRequests(): void
    {
        if ($this->personId === null) {
            $this->medicationRequests = [];

            return;
        }

        $startedAtFrom = $startedAtTo = null;
        if (!empty($this->filterStartedAtRange)) {
            $parts = array_map('trim', explode('—', $this->filterStartedAtRange));
            $startedAtFrom = $parts[0] ?? null;
            $startedAtTo = $parts[1] ?? $startedAtFrom;
        }
        $endedAtFrom = $endedAtTo = null;
        if (!empty($this->filterEndedAtRange)) {
            $parts = array_map('trim', explode('—', $this->filterEndedAtRange));
            $endedAtFrom = $parts[0] ?? null;
            $endedAtTo = $parts[1] ?? $endedAtFrom;
        }
        $createdAtFrom = $createdAtTo = null;
        if (!empty($this->filterCreatedAtRange)) {
            $parts = array_map('trim', explode('—', $this->filterCreatedAtRange));
            $createdAtFrom = $parts[0] ?? null;
            $createdAtTo = $parts[1] ?? $createdAtFrom;
        }
        $dispenseStartFrom = $dispenseStartTo = null;
        if (!empty($this->filterDispenseAvailableFromRange)) {
            $parts = array_map('trim', explode('—', $this->filterDispenseAvailableFromRange));
            $dispenseStartFrom = $parts[0] ?? null;
            $dispenseStartTo = $parts[1] ?? $dispenseStartFrom;
        }
        $dispenseEndFrom = $dispenseEndTo = null;
        if (!empty($this->filterDispenseAvailableToRange)) {
            $parts = array_map('trim', explode('—', $this->filterDispenseAvailableToRange));
            $dispenseEndFrom = $parts[0] ?? null;
            $dispenseEndTo = $parts[1] ?? $dispenseEndFrom;
        }

        $this->medicationRequests = app(MedicationRequestRepository::class)->searchByPersonId(
            $this->personId,
            [
                'status' => $this->filterStatus !== '' ? $this->filterStatus : null,
                'started_at_from' => $startedAtFrom,
                'started_at_to' => $startedAtTo,
                'ended_at_from' => $endedAtFrom,
                'ended_at_to' => $endedAtTo,
                'created_at_from' => $createdAtFrom,
                'created_at_to' => $createdAtTo,
                'dispense_start_from' => $dispenseStartFrom,
                'dispense_start_to' => $dispenseStartTo,
                'dispense_end_from' => $dispenseEndFrom,
                'dispense_end_to' => $dispenseEndTo,
                'request_number' => $this->filterRequestNumber !== '' ? $this->filterRequestNumber : null,
                'medication' => $this->filterMedication !== '' ? $this->filterMedication : null,
                'interaction_id' => $this->filterInteractionId !== '' ? $this->filterInteractionId : null,
                'care_plan_id' => $this->filterCarePlanId !== '' ? $this->filterCarePlanId : null,
                'doctor' => $this->filterDoctor !== '' ? $this->filterDoctor : null,
                'episode_id' => $this->filterEpisodeId !== '' ? $this->filterEpisodeId : null,
                'legal_entity' => $this->filterLegalEntity !== '' ? $this->filterLegalEntity : null,
                'medical_program' => $this->filterMedicalProgram !== '' ? $this->filterMedicalProgram : null,
            ]
        );
    }

    public function applyFilters(): void
    {
        $this->loadMedicationRequests();
    }

    public function resetFilters(): void
    {
        $this->reset([
            'filterStatus',
            'filterStartedAtRange',
            'filterEndedAtRange',
            'filterRequestNumber',
            'filterMedication',
            'filterInteractionId',
            'filterCarePlanId',
            'filterDoctor',
            'filterEpisodeId',
            'filterLegalEntity',
            'filterMedicalProgram',
            'filterCreatedAtRange',
            'filterDispenseAvailableFromRange',
            'filterDispenseAvailableToRange',
        ]);
        $this->loadMedicationRequests();
    }

    public function render(): View
    {
        return view('livewire.person.records.medication-requests');
    }
}
