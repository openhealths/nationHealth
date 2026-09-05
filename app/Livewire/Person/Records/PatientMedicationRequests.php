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

        $this->medicationRequests = app(MedicationRequestRepository::class)->searchByPersonId(
            $this->personId,
            [
                'status' => $this->filterStatus !== '' ? $this->filterStatus : null,
                'started_at_from' => $this->filterStartedAtFrom !== '' ? $this->filterStartedAtFrom : null,
                'started_at_to' => $this->filterStartedAtTo !== '' ? $this->filterStartedAtTo : null,
                'ended_at_from' => $this->filterEndedAtFrom !== '' ? $this->filterEndedAtFrom : null,
                'ended_at_to' => $this->filterEndedAtTo !== '' ? $this->filterEndedAtTo : null,
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
            'filterStartedAtFrom',
            'filterStartedAtTo',
            'filterEndedAtFrom',
            'filterEndedAtTo',
        ]);
        $this->loadMedicationRequests();
    }

    public function render(): View
    {
        return view('livewire.person.records.medication-requests');
    }
}
