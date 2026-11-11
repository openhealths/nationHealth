<?php

declare(strict_types=1);

namespace App\Livewire\Person\Records;

use App\Models\MedicalEvents\Sql\Medications\MedicationRequestRequest;

class PatientPrescriptionRequestView extends PatientMedicationRequestView
{
    protected function resourceType(): string
    {
        return MedicationRequestRequest::TYPE_REQUEST;
    }
}
