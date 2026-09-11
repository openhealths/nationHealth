<?php

declare(strict_types=1);

namespace App\Livewire\Person\Records;

use Illuminate\Contracts\View\View;

class PatientPrescriptionRequests extends BasePatientComponent
{
    /** @var list<array<string, mixed>> */
    public array $prescriptionRequests = [];

    public string $filterStatus = '';
    public string $filterDoctor = '';
    public string $filterLegalEntity = '';

    public string $filterInteractionId = '';
    public string $filterCarePlanId = '';
    public string $filterAppointmentId = '';
    public string $filterEpisodeId = '';

    public bool $showAdditionalParams = false;

    protected function initializeComponent(): void
    {
        $this->loadPrescriptionRequests();
    }

    public function loadPrescriptionRequests(): void
    {
        // TODO: Implement fetching prescription requests from backend when the API is ready.
        // For now, return an empty array instead of fake data.
        $this->prescriptionRequests = [];
    }

    public function applyFilters(): void
    {
        $this->loadPrescriptionRequests();
    }

    public function resetFilters(): void
    {
        $this->reset([
            'filterStatus',
            'filterDoctor',
            'filterLegalEntity',
            'filterInteractionId',
            'filterCarePlanId',
            'filterAppointmentId',
            'filterEpisodeId',
        ]);
        $this->loadPrescriptionRequests();
    }

    public function render(): View
    {
        return view('livewire.person.records.patient-prescription-requests');
    }
}
