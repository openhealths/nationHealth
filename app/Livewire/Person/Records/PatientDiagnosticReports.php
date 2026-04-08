<?php

declare(strict_types=1);

namespace App\Livewire\Person\Records;

use App\Livewire\Person\Records\BasePatientComponent;
use Livewire\Attributes\Url;

class PatientDiagnosticReports extends BasePatientComponent
{
    public string $filterService = '';

    public string $filterEcozId = '';

    public string $filterDoctor = '';

    public string $filterCreatedAtRange = '';

    public string $filterStatus = '';

    public string $filterCategory = '';

    public string $filterReferral = '';

    public string $filterConclusion = '';

    public bool $showAdditionalParams = false;

    public function render()
    {
        return view('livewire.person.records.diagnostic-reports');
    }

    public function search(): void
    {

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
            'filterReferral',
            'filterConclusion',
        ]);
    }
}
