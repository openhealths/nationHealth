<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\MedicalEvents\Approval;

use App\Enums\CarePlanTermsOfService;
use App\Models\CarePlan;
use App\Models\LegalEntity;

trait SelectsCarePlanApprovalAccess
{
    protected function skipsPatientOtp(CarePlan $carePlan, ?LegalEntity $legalEntity = null): bool
    {
        $legalEntity ??= legalEntity();
        if ($legalEntity === null) {
            return false;
        }

        $terms = $carePlan->termsOfService;
        if (is_array($terms)) {
            $terms = $terms['coding'][0]['code'] ?? $terms['code'] ?? '';
        }

        if (strtoupper(trim((string) $terms)) !== CarePlanTermsOfService::INPATIENT->value) {
            return false;
        }

        // Newly created plans may not have legalEntityId yet; they are still this facility.
        $planLegalEntityId = $carePlan->legalEntityId;
        if ($planLegalEntityId === null || $planLegalEntityId === '') {
            return true;
        }

        return (int) $planLegalEntityId === (int) $legalEntity->id;
    }

    protected function resolveAccessLevel(CarePlan $carePlan, ?LegalEntity $legalEntity = null): string
    {
        $legalEntity ??= legalEntity();

        return (int) $carePlan->legalEntityId === (int) $legalEntity?->id ? 'write' : 'read';
    }
}
