<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\MedicalEvents\MedicationRequest;

use App\Classes\eHealth\EHealth;
use App\Enums\Person\MedicationRequestStatus;
use App\Models\CarePlan;
use App\Models\CarePlanActivity;
use App\Models\MedicalEvents\Sql\Encounter;
use App\Models\MedicalEvents\Sql\Medications\MedicationRequestRequest;

trait SignsMedicationRequests
{
    use ValidatesMedicationRequestSignature;
    use PreparesMedicationRequestSigning;
    use MedicationRequestMessages;

    protected function signMedicationRequest(
        CarePlan|Encounter $contextModel,
        MedicationRequestRequest $requestRecord,
        array $formData = [],
        string $informWith = '',
        float $remainingQty = 0.0
    ): array {
        $this->requireMedicationRequestKep($formData);

        $activityUuid = $requestRecord->basedOn?->value;
        if ($activityUuid) {
            $activityForQty = CarePlanActivity::query()->where('uuid', $activityUuid)->first();
            if ($activityForQty !== null) {
                app(\App\Repositories\CarePlanActivityRepository::class)->assertCanIssue(
                    (int) $activityForQty->id,
                    (float) ($requestRecord->medicationQty ?? 0),
                    function (int $activityId) use ($requestRecord, $activityUuid): float {
                        return (float) MedicationRequestRequest::query()
                            ->whereHas('basedOn', fn ($q) => $q->where('value', $activityUuid))
                            ->where('uuid', '!=', $requestRecord->uuid)
                            ->whereNotIn('status', \App\Enums\MedicalEvents\RequestQuantityStatus::excluded(reserveDrafts: true))
                            ->sum('medication_qty');
                    }
                );
            }
        }

        $signedContent = signatureService()->signData(
            $this->buildSignPayload($contextModel, $requestRecord, $informWith),
            $formData['password'],
            $formData['knedp'],
            $formData['keyContainerUpload'] ?? null,
            $this->medicationRequestSignerTaxId($formData)
        );

        $payload = [
            'signed_medication_request_request' => $signedContent,
            'signed_content_encoding' => 'base64',
        ];

        $response = EHealth::medicationRequest()->signAndResolve($requestRecord->uuid, $payload);

        $result = $response['data'] ?? $response;

        $requestRecord->update(['status' => MedicationRequestStatus::ACTIVE->value]);

        $requestNumber = (string) (
            $result['request_number']
            ?? ($result['medication_request']['request_number'] ?? null)
            ?? $requestRecord->requestNumber
            ?? $requestRecord->uuid
        );

        $informWithRaw = $informWith !== ''
            ? $informWith
            : (string) ($requestRecord->informWith ?? '');

        $notificationDisabled = filter_var(
            $formData['request_notification_disabled'] ?? false,
            FILTER_VALIDATE_BOOLEAN
        );

        $result['success_message'] = $this->buildPostSignSuccessMessage(
            $requestNumber,
            $informWithRaw,
            $notificationDisabled
        );

        $medicationQty = (float) ($requestRecord->medicationQty ?? 0);
        if ($this->shouldWarnRemainingQty($remainingQty, $medicationQty)) {
            $unit = (string) ($formData['medication_unit'] ?? 'од.');
            $result['warning_message'] = $this->buildRemainingQtyWarningMessage($remainingQty, $unit);
            $result['show_remaining_qty_warning'] = true;
        }

        return $result;
    }
}
