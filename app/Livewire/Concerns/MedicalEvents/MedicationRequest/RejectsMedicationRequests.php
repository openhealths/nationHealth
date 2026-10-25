<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\MedicalEvents\MedicationRequest;

use App\Classes\eHealth\EHealth;
use App\Enums\Person\MedicationRequestStatus;
use App\Models\CarePlan;
use App\Models\MedicalEvents\Sql\Encounter;
use App\Models\MedicalEvents\Sql\Medications\MedicationRequestRequest;
use InvalidArgumentException;

trait RejectsMedicationRequests
{
    use ValidatesMedicationRequestSignature;
    use ResolvesActiveMedicationRequest;

    protected function rejectMedicationRequest(
        CarePlan|Encounter $contextModel,
        MedicationRequestRequest $requestRecord,
        array $formData = [],
        string $statusReason = ''
    ): array {
        if (MedicationRequestStatus::resolve((string) $requestRecord->status)?->isUnsigned()) {
            EHealth::medicationRequest()->rejectRequest((string) $requestRecord->uuid)->getData();

            $requestRecord->update(['status' => MedicationRequestStatus::REJECTED->value]);

            return [];
        }

        $this->requireMedicationRequestKep($formData);

        $personUuid = $contextModel instanceof CarePlan
            ? $contextModel->person->uuid
            : ($requestRecord->person_uuid ?? ($requestRecord->person->uuid ?? ''));

        if ($statusReason === '') {
            throw new InvalidArgumentException('Для відхилення активного рецепта потрібен код причини (reject_reason_code).');
        }

        $signerTaxId = $this->medicationRequestSignerTaxId($formData);
        $activeId = $this->resolveActiveMedicationRequestId($personUuid, (string) $requestRecord->uuid);

        $signedContent = signatureService()->signData(
            $this->buildRejectSignPayload($personUuid, $activeId, $statusReason, $formData),
            $formData['password'],
            $formData['knedp'],
            $formData['keyContainerUpload'] ?? null,
            $signerTaxId
        );

        $payload = [
            'person_id' => $personUuid,
            'signed_content' => $signedContent,
            'signed_content_encoding' => 'base64',
        ];

        $response = EHealth::medicationRequest()->rejectAndResolve($personUuid, $activeId, $payload);

        $result = $response['data'] ?? $response;

        // eHealth can echo the pre-reject status back, so an active answer still means rejected here.
        $reported = MedicationRequestStatus::resolve((string) ($result['status'] ?? ''));
        $newStatus = $reported === null || $reported === MedicationRequestStatus::ACTIVE
            ? MedicationRequestStatus::REJECTED
            : $reported;

        $requestRecord->update(['status' => $newStatus->value]);

        return $result;
    }

    protected function buildRejectSignPayload(string $personUuid, string $activeId, string $statusReason, array $formData = []): array
    {
        $medicationRequest = EHealth::medicationRequest()->getForRejectSigning($personUuid, $activeId);
        $medicationRequest['id'] = $medicationRequest['id'] ?? $activeId;
        $medicationRequest['reject_reason_code'] = $statusReason;

        $rejectReason = trim((string) ($formData['reject_reason'] ?? ''));
        if ($rejectReason !== '') {
            $medicationRequest['reject_reason'] = $rejectReason;
        }

        return $medicationRequest;
    }
}
