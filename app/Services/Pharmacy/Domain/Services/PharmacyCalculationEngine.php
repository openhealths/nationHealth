<?php

namespace App\Services\Pharmacy\Domain\Services;

use InvalidArgumentException;

/**
 * Розрахунковий модуль вартості, сум відшкодування та доплати пацієнта
 * відповідно до п. 3.5.1.2.7, 3.5.1.2.8, 3.5.1.3.1, 3.5.2.2.4 та 3.5.2.3.1.
 */
class PharmacyCalculationEngine
{
    /**
     * Розрахунок сум для ЛЗ за програмою НСЗУ (NHS)
     */
    public function calculateMedicationNhsAmounts(
        float $sellPrice,
        float $reimbursementPerPackage,
        float $requestedPackagesCount
    ): array {
        $sellAmount = round($sellPrice * $requestedPackagesCount, 2);
        $discountAmount = round($reimbursementPerPackage * $requestedPackagesCount, 2);

        if ($discountAmount > $sellAmount) {
            $discountAmount = $sellAmount;
        }

        $estimatedPatientPayment = round($sellAmount - $discountAmount, 2);
        if ($estimatedPatientPayment < 0) {
            $estimatedPatientPayment = 0.00;
        }

        return [
            'sell_price' => $sellPrice,
            'reimbursement_amount' => $reimbursementPerPackage,
            'sell_amount' => $sellAmount,
            'discount_amount' => $discountAmount,
            'estimated_payment_amount' => $estimatedPatientPayment,
        ];
    }

    /**
     * Розрахунок сум для медичного виробу
     * Формула згідно п. 3.5.2.3.1:
     * discount_amount = program_device.reimbursement_amount * (dispense_details.quantity.value / device_definition.packaging_count)
     */
    public function calculateDeviceDiscountAmount(
        float $reimbursementAmountPerPackage,
        float $quantityValue,
        int $packagingCount,
        float $sellPricePerPackage
    ): array {
        if ($packagingCount <= 0) {
            throw new InvalidArgumentException("Кількість виробів в упаковці (packaging_count) повинна бути більше 0");
        }

        $packagesCount = $quantityValue / $packagingCount;
        $totalSellAmount = round($sellPricePerPackage * $packagesCount, 2);
        $calculatedDiscount = round($reimbursementAmountPerPackage * $packagesCount, 2);

        if ($calculatedDiscount > $totalSellAmount) {
            $calculatedDiscount = $totalSellAmount;
        }

        $patientPayment = round($totalSellAmount - $calculatedDiscount, 2);
        if ($patientPayment < 0) {
            $patientPayment = 0.00;
        }

        return [
            'packages_count' => $packagesCount,
            'sell_price' => $sellPricePerPackage,
            'sell_amount' => $totalSellAmount,
            'discount_amount' => $calculatedDiscount,
            'estimated_payment_amount' => $patientPayment,
        ];
    }

    /**
     * Валідація коригування вартості (п. 3.5.1.3.1: виключно у меншу сторону)
     */
    public function validatePriceAdjustment(
        float $adjustedValue,
        float $baseValueFromRegistry,
        string $fieldName
    ): void {
        if ($adjustedValue > $baseValueFromRegistry) {
            throw new InvalidArgumentException(
                sprintf(
                    "Поле '%s' (%s грн) не може перевищувати значення згідно реєстру (%s грн). Дозволено коригування виключно у меншу сторону.",
                    $fieldName,
                    number_format($adjustedValue, 2),
                    number_format($baseValueFromRegistry, 2)
                )
            );
        }

        if ($adjustedValue < 0) {
            throw new InvalidArgumentException("Значення поля '{$fieldName}' не може бути менше 0");
        }
    }
}
