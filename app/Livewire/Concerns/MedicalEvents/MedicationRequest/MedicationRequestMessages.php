<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\MedicalEvents\MedicationRequest;

trait MedicationRequestMessages
{
    protected function buildPostSignSuccessMessage(
        string $requestNumber,
        string $informWithRaw,
        bool $requestNotificationDisabled,
        ?string $maskedPhone = null
    ): string {
        $parts = explode('|', $informWithRaw);
        $authType = strtoupper(trim((string) ($parts[1] ?? '')));
        $phoneFromInform = trim((string) ($parts[2] ?? ''));
        $phone = $maskedPhone !== null && $maskedPhone !== ''
            ? $maskedPhone
            : ($phoneFromInform !== '' ? $phoneFromInform : '•••');

        $usesSms = !$requestNotificationDisabled
            && in_array($authType, ['OTP', 'THIRD_PERSON'], true);

        if ($usesSms) {
            return __('care-plan.eprescription_signed_sms', [
                'number' => $requestNumber,
                'phone' => $phone,
            ]);
        }

        return __('care-plan.eprescription_signed_print', [
            'number' => $requestNumber,
        ]);
    }

    protected function shouldWarnRemainingQty(float $remainingBefore, float $medicationQty): bool
    {
        if ($medicationQty <= 0) {
            return false;
        }

        return ($remainingBefore - $medicationQty) < $medicationQty;
    }

    protected function buildRemainingQtyWarningMessage(float $remainingBefore, string $unit): string
    {
        return __('care-plan.eprescription_remaining_qty_warning', [
            'remaining' => $remainingBefore,
            'unit' => $unit !== '' ? $unit : 'од.',
        ]);
    }
}
