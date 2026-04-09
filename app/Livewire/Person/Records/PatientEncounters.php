<?php

declare(strict_types=1);

namespace App\Livewire\Person\Records;

use App\Livewire\Person\Records\BasePatientComponent;
use Livewire\Attributes\Url;

class PatientEncounters extends BasePatientComponent
{
    public string $filterStartDateRange = '';

    public string $filterEndDateRange = '';

    public string $filterEcozId = '';

    public string $filterReferral = '';

    public string $filterStatus = '';

    public string $filterClass = '';

    public string $filterSpeciality = '';

    public string $filterType = '';

    public bool $showAdditionalParams = false;

    public function render()
    {
        return view('livewire.person.records.encounter');
    }

    public function search(): void
    {

    }

    public function resetFilters(): void
    {
        $this->reset([
            'filterStartDateRange',
            'filterEndDateRange',
            'filterEcozId',
            'filterReferral',
            'filterStatus',
            'filterClass',
            'filterSpeciality',
            'filterType',
        ]);
    }
}
