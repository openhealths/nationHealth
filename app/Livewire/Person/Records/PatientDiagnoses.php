<?php

declare(strict_types=1);

namespace App\Livewire\Person\Records;

use Livewire\Attributes\Url;

class PatientDiagnoses extends BasePatientComponent
{
    #[Url]
    public string $filterCode = '';

    #[Url]
    public string $filterEcozId = '';

    #[Url]
    public string $filterMedicalRecordId = '';

    #[Url]
    public string $filterCreatedAtRange = '';

    #[Url]
    public string $filterClinicalStatus = '';

    #[Url]
    public string $filterSeverity = '';

    #[Url]
    public string $filterStartedAtRange = '';

    #[Url]
    public string $filterVerificationStatus = '';

    #[Url]
    public string $filterBodyPart = '';

    #[Url]
    public string $filterPerformer = '';

    #[Url]
    public string $filterSource = '';

    public bool $showAdditionalParams = false;

    public function render()
    {
        return view('livewire.person.records.diagnose');
    }

    public function search(): void
    {

    }

    public function syncDiagnoses(): void
    {

    }

    public function resetFilters(): void
    {
        $this->reset([
            'filterCode',
            'filterEcozId',
            'filterMedicalRecordId',
            'filterCreatedAtRange',
            'filterClinicalStatus',
            'filterSeverity',
            'filterStartedAtRange',
            'filterVerificationStatus',
            'filterBodyPart',
            'filterPerformer',
            'filterSource',
        ]);
    }
}
