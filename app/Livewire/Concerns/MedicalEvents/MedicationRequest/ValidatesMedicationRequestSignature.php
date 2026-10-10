<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\MedicalEvents\MedicationRequest;

use InvalidArgumentException;

trait ValidatesMedicationRequestSignature
{
    protected function requireMedicationRequestKep(array $formData): void
    {
        if (trim((string) ($formData['password'] ?? '')) === '' || trim((string) ($formData['knedp'] ?? '')) === '') {
            throw new InvalidArgumentException(__('care-plan.kep_signature_required'));
        }

        $this->medicationRequestSignerTaxId($formData);
    }

    protected function medicationRequestSignerTaxId(array $formData): string
    {
        $taxId = trim((string) ($formData['signer_tax_id'] ?? ''));
        if ($taxId === '') {
            throw new InvalidArgumentException(__('care-plan.signer_tax_id_required'));
        }

        return $taxId;
    }
}
