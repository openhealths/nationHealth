<?php

declare(strict_types=1);

namespace App\Livewire\Person\Records;

use App\Livewire\Person\Records\BasePatientComponent;
use Livewire\Attributes\Url;

class PatientVaccination extends BasePatientComponent
{
    public string $filterVaccine = '';

    public string $filterEcozId = '';

    public string $filterMedicalRecordId = '';

    public string $filterCreatedAtRange = '';

    public string $filterEnteredAtRange = '';

    public string $filterPerformer = '';

    public string $filterSource = '';

    public string $filterStatus = '';

    public string $filterDosage = '';

    public string $filterManufacturer = '';

    public string $filterReason = '';

    public string $filterBodyPart = '';

    public string $filterWasPerformed = '';

    public string $filterTargetDisease = '';

    public string $filterProtocolAuthor = '';

    public string $filterDoseSequence = '';

    public string $filterDoseCount = '';

    public string $filterImmunizationStage = '';

    public bool $showAdditionalParams = false;

    public function render()
    {
        return view('livewire.person.records.vaccination');
    }

    public function search(): void
    {

    }

    public function syncVaccinations(): void
    {

    }

    public function resetFilters(): void
    {
        $this->reset([
            'filterVaccine',
            'filterEcozId',
            'filterMedicalRecordId',
            'filterCreatedAtRange',
            'filterEnteredAtRange',
            'filterPerformer',
            'filterSource',
            'filterStatus',
            'filterDosage',
            'filterManufacturer',
            'filterReason',
            'filterBodyPart',
            'filterWasPerformed',
            'filterTargetDisease',
            'filterProtocolAuthor',
            'filterDoseSequence',
            'filterDoseCount',
            'filterImmunizationStage',
        ]);
    }
}
