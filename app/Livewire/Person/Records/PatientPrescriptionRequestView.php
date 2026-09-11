<?php

declare(strict_types=1);

namespace App\Livewire\Person\Records;

use Illuminate\Contracts\View\View;

/**
 * TODO: This is currently a mockup component.
 * Loading data by `$requestId` and full data binding to the blade view must be implemented
 * before merging to main as a complete feature.
 */
class PatientPrescriptionRequestView extends BasePatientComponent
{
    public string $requestId;

    public function mount(\App\Models\LegalEntity $legalEntity, ?\App\Models\Person\Person $person = null, ?\App\Models\Preperson $preperson = null, ?string $requestId = null): void
    {
        parent::mount($legalEntity, $person, $preperson);
        $this->requestId = $requestId ?? '';
    }

    public function render(): View
    {
        return view('livewire.person.records.patient-prescription-request-view');
    }
}
