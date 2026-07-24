<?php

declare(strict_types=1);

namespace App\Services\MedicalEvents;

use App\Classes\eHealth\EHealth;
use App\Classes\eHealth\EHealthResponse;
use App\Exceptions\EHealth\EHealthValidationException;
use App\Models\CarePlan;
use App\Repositories\MedicalEvents\Repository;

class MedicationRequestLifecycleService
{
    public function fetchPrintoutFromEhealth(string $personUuid, string $prescriptionId): ?string
    {
        try {
            $response = EHealth::person()->getMedicationRequestPrintoutForm($personUuid, $prescriptionId);
            $printout = $response->getData()['printout_form'] ?? null;

            return !empty($printout) ? $printout : null;
        } catch (EHealthValidationException) {
            return null;
        }
    }

    public function buildFallbackPrintoutHtml(CarePlan $carePlan, string $prescriptionId, ?string $signatureText = null): string
    {
        $requestRecord = Repository::medicationRequest()->findByUuid($prescriptionId);
        $medicationName = $requestRecord?->medication_id ?? $prescriptionId;
        $signatureText ??= $requestRecord?->note ?? '';

        return "
            <div style='font-family: sans-serif; padding: 40px; max-width: 600px; margin: 0 auto; border: 1px solid #ccc; border-radius: 8px;'>
                <h2 style='text-align: center; color: #1e3a8a;'>ІНФОРМАЦІЙНА ПАМ’ЯТКА ПАЦІЄНТА</h2>
                <p style='text-align: center; font-size: 14px; color: #555;'>Електронний рецепт № " . e($requestRecord?->request_number ?? $prescriptionId) . "</p>
                <hr style='border-top: 1px solid #eee; margin: 20px 0;'/>
                <table style='width: 100%; font-size: 14px; border-collapse: collapse;'>
                    <tr><td style='padding: 8px 0; font-weight: bold;'>Пацієнт:</td><td style='padding: 8px 0;'>" . e($carePlan->person->full_name) . "</td></tr>
                    <tr><td style='padding: 8px 0; font-weight: bold;'>Лікарський засіб (МНН):</td><td style='padding: 8px 0;'>" . e($medicationName) . "</td></tr>
                    <tr><td style='padding: 8px 0; font-weight: bold;'>Код погашення:</td><td style='padding: 8px 0; font-size: 18px; font-weight: bold; color: #10b981;'>[Код в СМС / Доступний в аптеці]</td></tr>
                    <tr><td style='padding: 8px 0; font-weight: bold;'>Сигнатура:</td><td style='padding: 8px 0;'>" . e($signatureText) . "</td></tr>
                </table>
                <div style='margin-top: 40px; text-align: center; font-size: 12px; color: #888;'>
                    Виписано в МІС. Дякуємо, що користуєтесь нашими послугами!
                </div>
            </div>
        ";
    }

    public function resendSms(string $personUuid, string $prescriptionId): EHealthResponse
    {
        return EHealth::medicationRequest()->resendOtp($personUuid, $prescriptionId);
    }
}
