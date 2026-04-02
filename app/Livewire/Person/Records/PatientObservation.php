<?php

namespace App\Livewire\Person\Records;

use App\Livewire\Person\Records\BasePatientComponent;
use Livewire\Attributes\Url;

class PatientObservation extends BasePatientComponent
{
    public string $filterService = '';

    public string $filterEcozId = '';

    public string $filterDoctor = '';

    public string $filterCreatedAtRange = '';

    public string $filterStatus = '';

    public string $filterCategory = '';

    public string $filterMethod = '';

    public string $filterCode = '';

    public string $filterBodyPart = '';

    public string $filterInterpretation = '';

    public string $filterValue = '';

    public bool $showAdditionalParams = false;

    public function render()
    {
        return view('livewire.person.records.observations');
    }

    public function searchReports(): void
    {
        // Search logic here
    }

    public function syncObservations(): void
    {
        // Sync logic here
    }

    public function resetFilters(): void
    {
        $this->reset([
            'filterService',
            'filterEcozId',
            'filterDoctor',
            'filterCreatedAtRange',
            'filterStatus',
            'filterCategory',
            'filterMethod',
            'filterCode',
            'filterBodyPart',
            'filterInterpretation',
            'filterValue',
        ]);
    }
}
