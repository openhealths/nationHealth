<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Livewire\CarePlan\Concerns\ManagesCarePlanEPrescription;
use App\Livewire\Encounter\Concerns\ManagesEncounterEPrescription;

/** Exposes protected workflow steps for behavior tests; production has no service delegate. */
class MedicationRequestWorkflowHarness
{
    use ManagesCarePlanEPrescription {
        ManagesCarePlanEPrescription::buildPostSignSuccessMessage insteadof ManagesEncounterEPrescription;
        ManagesCarePlanEPrescription::shouldWarnRemainingQty insteadof ManagesEncounterEPrescription;
        ManagesCarePlanEPrescription::buildRemainingQtyWarningMessage insteadof ManagesEncounterEPrescription;
        createCarePlanMedicationDraft as public createCarePlanDraft;
        ManagesCarePlanEPrescription::signMedicationRequest as public signPrescription;
        medicationRequestPrintoutHtml as public buildFallbackPrintoutHtml;
        ManagesCarePlanEPrescription::buildPostSignSuccessMessage as public;
        ManagesCarePlanEPrescription::shouldWarnRemainingQty as public;
        ManagesCarePlanEPrescription::buildRemainingQtyWarningMessage as public;
        resolveActiveMedicationRequestId as public resolveActiveEhealthId;
    }
    use ManagesEncounterEPrescription {
        createEncounterMedicationDraft as public createEncounterDraft;
    }

    public function rejectPrescription(\App\Models\CarePlan|\App\Models\MedicalEvents\Sql\Encounter $context, \App\Models\MedicalEvents\Sql\Medications\MedicationRequestRequest $record, array $formData = [], string $reason = ''): array
    {
        return $this->rejectMedicationRequest($context, $record, $formData, $reason);
    }

    public function findEligibleEncountersForEPrescription(int $personId, ?string $employeeUuid): \Illuminate\Support\Collection
    {
        return app(\App\Repositories\MedicalEvents\EncounterRepository::class)->findEligibleEncountersForEPrescription($personId, $employeeUuid);
    }
}
