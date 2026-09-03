<?php

namespace App\Services\Pharmacy\Domain\Services;

use DateTimeImmutable;
use App\Services\Pharmacy\Domain\Dictionaries\PharmacyDictionaries;
use DomainException;
use InvalidArgumentException;

/**
 * Валідаційний сервіс бізнес-правил ТЗ 3.5 ЕСОЗ
 */
class PharmacyValidationService
{
    /**
     * 3.5.1.1.2 - 3.5.1.1.4: Валідація статусу та доступності ЕР для погашення
     */
    public function validateMedicationRequestStatus(array $medicationRequest, string $currentLegalEntityId): array
    {
        $status = $medicationRequest['status'] ?? '';
        $result = [
            'can_dispense' => true,
            'reason' => null,
            'details' => [],
        ];

        if ($status === 'COMPLETED') {
            $result['can_dispense'] = false;
            $result['reason'] = 'Електронний рецепт вже погашений (статус COMPLETED). Повторне погашення неможливе.';
            return $result;
        }

        if ($status === 'EXPIRED') {
            $result['can_dispense'] = false;
            $result['reason'] = 'Строк дії електронного рецепта вичерпано (статус EXPIRED). Погашення неможливе.';
            return $result;
        }

        if ($status === 'REJECTED') {
            $rejectCode = $medicationRequest['reject_reason_code'] ?? 'UNKNOWN';
            $rejectReasonName = PharmacyDictionaries::MEDICATION_REQUEST_REJECT_REASON[$rejectCode] ?? $rejectCode;
            $rejectComment = $medicationRequest['reject_reason'] ?? '';

            $result['can_dispense'] = false;
            $result['reason'] = "Електронний рецепт відхилено (статус REJECTED). Причина: {$rejectReasonName}";
            $result['details'] = [
                'reject_reason_code' => $rejectCode,
                'reject_reason_name' => $rejectReasonName,
                'reject_reason' => $rejectComment,
            ];
            return $result;
        }

        // Перевірка блокування/резервування
        if (!empty($medicationRequest['blocked_to'])) {
            $blockedToDate = new DateTimeImmutable($medicationRequest['blocked_to']);
            $now = new DateTimeImmutable();

            if ($blockedToDate > $now) {
                $blockedBy = $medicationRequest['blocked_by_legal_entity'] ?? [];
                $blockedLegalEntityId = $blockedBy['id'] ?? null;

                $blockCode = $medicationRequest['block_reason_code'] ?? '';
                $blockReasonName = PharmacyDictionaries::MEDICATION_REQUEST_BLOCK_REASON[$blockCode] ?? $blockCode;

                $result['details'] = [
                    'blocked_to' => $medicationRequest['blocked_to'],
                    'block_reason_code' => $blockCode,
                    'block_reason_name' => $blockReasonName,
                    'block_reason' => $medicationRequest['block_reason'] ?? '',
                    'blocked_by' => [
                        'public_name' => $blockedBy['public_name'] ?? '',
                        'edrpou' => $blockedBy['edrpou'] ?? '',
                    ],
                ];

                if ($blockedLegalEntityId !== $currentLegalEntityId) {
                    $result['can_dispense'] = false;
                    $result['reason'] = sprintf(
                        "Електронний рецепт зарезервовано іншим аптечним закладом (%s, ЄДРПОУ %s) до %s. Погашення неможливе.",
                        $blockedBy['public_name'] ?? 'Невідомий АЗ',
                        $blockedBy['edrpou'] ?? '-',
                        $medicationRequest['blocked_to']
                    );
                    return $result;
                }
            }
        }

        return $result;
    }

    /**
     * 3.5.2.1.3 - 3.5.2.1.5: Валідація статусу та строків е-запиту на медичні вироби
     */
    public function validateDeviceRequestStatus(array $deviceRequest): array
    {
        $status = $deviceRequest['status'] ?? '';
        $result = [
            'can_dispense' => true,
            'reason' => null,
            'details' => [],
        ];

        if ($status === 'COMPLETED') {
            $result['can_dispense'] = false;
            $result['reason'] = 'Електронний запит на медичний виріб вже погашений (статус COMPLETED). Погашення неможливе.';
            return $result;
        }

        if ($status === 'REVOKED') {
            $reasonCode = $deviceRequest['status_reason']['code'] ?? ($deviceRequest['revoke_reason_code'] ?? 'UNKNOWN');
            $reasonName = PharmacyDictionaries::DEVICE_REQUEST_REVOKE_REASONS[$reasonCode] ?? $reasonCode;

            $result['can_dispense'] = false;
            $result['reason'] = "Електронний запит відкликано/відхилено (статус REVOKED). Причина: {$reasonName}";
            $result['details'] = [
                'revoke_reason_code' => $reasonCode,
                'revoke_reason_name' => $reasonName,
            ];
            return $result;
        }

        if (!empty($deviceRequest['dispense_valid_to'])) {
            $validTo = new DateTimeImmutable($deviceRequest['dispense_valid_to']);
            $now = new DateTimeImmutable();
            if ($validTo < $now) {
                $result['can_dispense'] = false;
                $result['reason'] = 'Строк дії електронного запиту на медичні вироби вичерпано. Погашення неможливе.';
                return $result;
            }
        }

        return $result;
    }

    /**
     * 3.5.1.2.7.3: Перевірка вибору товарних позицій з єдиного реєстру відшкодування
     */
    public function validateSingleRegistrySelection(array $selectedParticipants): string
    {
        if (empty($selectedParticipants)) {
            throw new InvalidArgumentException("Не обрано жодного торговельного найменування");
        }

        $registryNumbers = [];
        foreach ($selectedParticipants as $item) {
            $regNum = $item['registry_number'] ?? 'DEFAULT';
            $registryNumbers[$regNum] = true;
        }

        if (count($registryNumbers) > 1) {
            throw new DomainException("Вибір торгових назв дозволено виключно з одного реєстру відшкодування");
        }

        return array_key_first($registryNumbers);
    }

    /**
     * 3.5.1.2.9 & 3.5.2.3.2: Перевірка ліміту сумарної кількості до видачі
     */
    public function validateTotalQuantity(float $totalRequestedQty, float $remainingAvailableQty, string $itemType = 'ЛЗ'): void
    {
        if ($totalRequestedQty <= 0) {
            throw new InvalidArgumentException("Кількість {$itemType} до видачі повинна бути більше 0");
        }

        if ($totalRequestedQty > $remainingAvailableQty) {
            throw new DomainException(
                sprintf(
                    "Сумарна кількість %s до видачі (%s) перевищує доступний залишок за рецептом (%s)",
                    $itemType,
                    $totalRequestedQty,
                    $remainingAvailableQty
                )
            );
        }
    }

    /**
     * 3.5.1.2.2 - 3.5.1.2.5 & 3.5.2.2.2: Трансляція специфічних системних помилок кваліфікації
     */
    public function translateQualificationError(string $rawErrorMessage, ?string $contractNumber = null, bool $isDevice = false): string
    {
        if (str_contains($rawErrorMessage, 'Division does not provide the medical program')) {
            return PharmacyDictionaries::ERROR_MESSAGES['DIVISION_NOT_PROVIDE_PROGRAM'];
        }

        if (str_contains($rawErrorMessage, 'Medical program provision is not related to any actual contract')) {
            return $isDevice 
                ? PharmacyDictionaries::ERROR_MESSAGES['DEVICE_PROGRAM_PROVISION_NOT_RELATED_CONTRACT']
                : PharmacyDictionaries::ERROR_MESSAGES['PROGRAM_PROVISION_NOT_RELATED_CONTRACT'];
        }

        if (str_contains($rawErrorMessage, 'is suspended')) {
            return sprintf(PharmacyDictionaries::ERROR_MESSAGES['CONTRACT_SUSPENDED_TEMPLATE'], $contractNumber ?? 'невідомий');
        }

        if (str_contains($rawErrorMessage, 'Division does not have active licenses')) {
            return PharmacyDictionaries::ERROR_MESSAGES['DIVISION_NO_ACTIVE_LICENSES'];
        }

        return $rawErrorMessage;
    }

    /**
     * 3.5.1.6.1.2: Формування тексту обов’язкового попередження перед резервуванням
     */
    public function getReservationWarningText(?string $blockedTo, string $dispenseValidTo): string
    {
        $targetDate = !empty($blockedTo) ? $blockedTo : $dispenseValidTo;
        return sprintf(PharmacyDictionaries::ERROR_MESSAGES['RESERVATION_WARNING_TEMPLATE'], $targetDate);
    }
}
