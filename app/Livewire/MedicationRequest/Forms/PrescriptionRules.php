<?php

declare(strict_types=1);

namespace App\Livewire\MedicationRequest\Forms;

use App\Services\MedicalEvents\Mappers\MedicationRequestMapper;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** TV39 prescribing rules shared by the two forms and checked again before submission. */
class PrescriptionRules
{
    public static function normalize(array $form, array $drug, array $program = [], ?float $planDailyDose = null): array
    {
        Validator::make($form, [
            'medication_qty' => ['required', 'numeric', 'gt:0'],
            'max_dose_per_administration' => ['required', 'numeric', 'gt:0'],
            'max_dose_per_period' => ['required', 'numeric', 'gt:0'],
            'signature_text' => ['required', 'string'],
            'started_at' => ['required', 'date'],
            'duration' => ['required', 'integer', 'min:1'],
        ])->validate();

        $mapper = new MedicationRequestMapper();
        $daily = $mapper->doseInMedicationUnits((float) $form['max_dose_per_period'], $form, $drug);
        $administration = $mapper->doseInMedicationUnits((float) $form['max_dose_per_administration'], $form, $drug);
        $duration = (int) $form['duration'];
        $qty = (float) $form['medication_qty'];
        $errors = [];
        $maxPeriod = self::programSetting($program, 'request_max_period_day');
        if ($maxPeriod !== null && (!is_numeric($maxPeriod) || (int) $maxPeriod < 1 || $duration > (int) $maxPeriod)) {
            $errors['duration'] = __('medication-requests.course_period_exceeded', ['max' => $maxPeriod]);
        }
        if (!self::isAllowedQuantity($qty, $drug)) {
            $errors['medication_qty'] = __('medication-requests.quantity_not_allowed');
        }
        if ($qty + 0.000001 < $daily * $duration) {
            $errors['medication_qty'] = __('medication-requests.quantity_course_minimum', ['quantity' => $daily * $duration]);
        }
        $maximumDaily = self::positiveNumber($drug['max_daily_dosage'] ?? null);
        if ($maximumDaily !== null && $daily > $maximumDaily) {
            $errors['max_dose_per_period'] = __('care-plan.eprescription_max_daily_dosage_exceeded', [
                'name' => $drug['name'] ?? '', 'max' => $maximumDaily, 'unit' => $mapper->medicationUnit($drug),
            ]);
        }
        if ($administration > $daily) {
            $errors['max_dose_per_administration'] = __('medication-requests.administration_exceeds_daily');
        }
        $recommended = self::positiveNumber($drug['daily_dosage'] ?? null);
        $requiresConfirmation = ($recommended !== null && $daily > $recommended) || ($planDailyDose !== null && $planDailyDose > 0 && $daily > $planDailyDose);
        if ($requiresConfirmation && !filter_var($form['dose_warning_confirmed'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $errors['dose_warning_confirmed'] = __('care-plan.eprescription_daily_dose_warning', ['name' => $drug['name'] ?? '']);
        }
        $disabled = filter_var(self::programSetting($program, 'request_notification_disabled', false), FILTER_VALIDATE_BOOLEAN);
        if (!$disabled) {
            if (trim((string) ($form['inform_with'] ?? '')) === '') {
                $errors['inform_with'] = __('encounters.eprescription.select_authentication');
            } elseif (!filter_var($form['phone_confirmed'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                $errors['phone_confirmed'] = __('medication-requests.phone_confirmation_required');
            }
        }
        $container = (string) ($form['container_dosage'] ?? '');
        if ($container !== '' && !in_array($container, array_column(self::containers($drug), 'value'), true)) {
            $errors['container_dosage'] = __('medication-requests.container_not_allowed');
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $form['started_at'] = CarbonImmutable::parse(convertToYmd($form['started_at']))->toDateString();
        $form['ended_at'] = CarbonImmutable::parse($form['started_at'])->addDays($duration - 1)->toDateString();
        $form['medication_unit'] = $mapper->medicationUnit($drug);
        $form['request_notification_disabled'] = $disabled;
        if ($disabled) {
            $form['inform_with'] = null;
        }
        if ($requiresConfirmation && !str_starts_with($form['signature_text'], '(!)')) {
            $form['signature_text'] = '(!) '.$form['signature_text'];
        }

        return $form;
    }

    public static function isAllowedQuantity(float $qty, array $drug): bool
    {
        if (!is_finite($qty) || $qty <= 0) {
            return false;
        }
        $packages = $drug['packages'] ?? [];
        if ($packages === []) {
            $max = self::positiveNumber($drug['max_request_dosage'] ?? null);

            return $max === null || $qty <= $max;
        }
        foreach ($packages as $package) {
            $step = self::positiveNumber($package['package_min_qty'] ?? ($package['min_qty'] ?? ($package['package_qty'] ?? ($package['medication_qty'] ?? null))));
            $max = self::positiveNumber($package['max_request_dosage'] ?? ($drug['max_request_dosage'] ?? null));
            if (($max === null || $qty <= $max) && ($step === null || ($qty + 0.000001 >= $step && abs($qty / $step - round($qty / $step)) < 0.000001))) {
                return true;
            }
        }

        return false;
    }

    /** General MIS list uses medical_program_settings; the program detail uses settings. */
    public static function programSetting(?array $program, string $key, mixed $default = null): mixed
    {
        if ($program === null) {
            return $default;
        }

        return data_get($program, 'medical_program_settings.'.$key, data_get($program, 'settings.'.$key, $default));
    }

    /** All permitted quantities within the supplied course/activity boundary, with no ten-item truncation. */
    public static function quantities(array $drug, ?float $remaining = null): array
    {
        $values = [];
        foreach ($drug['packages'] ?? [] as $package) {
            $step = self::positiveNumber($package['package_min_qty'] ?? ($package['min_qty'] ?? ($package['package_qty'] ?? ($package['medication_qty'] ?? null))));
            $max = self::positiveNumber($package['max_request_dosage'] ?? ($drug['max_request_dosage'] ?? null));
            if ($remaining !== null) {
                $max = $max === null ? $remaining : min($max, $remaining);
            }
            // Unbounded packages use a numeric input with these exact rules, rather than a truncated selector.
            if ($step === null || $max === null) {
                return [];
            }
            for ($i = 1; $i * $step <= $max + 0.000001; $i++) {
                $value = round($i * $step, 6);
                $values[(string) $value] = $value;
            }
        }
        sort($values, SORT_NUMERIC);

        return array_values($values);
    }

    public static function containers(array $drug): array
    {
        $choices = [];
        foreach ($drug['packages'] ?? [] as $package) {
            $dosage = $package['container_dosage'] ?? [];
            $amount = self::positiveNumber($dosage['numerator_value'] ?? ($dosage['value'] ?? null));
            $unit = $dosage['numerator_unit'] ?? null;
            if ($amount !== null && is_string($unit) && $unit !== '') {
                $value = $amount.'|'.$unit.'|'.$unit;
                $choices[$value] = ['value' => $value, 'label' => $amount.' '.$unit];
            }
        }

        return array_values($choices);
    }

    private static function positiveNumber(mixed $value): ?float
    {
        return is_numeric($value) && is_finite((float) $value) && (float) $value > 0 ? (float) $value : null;
    }
}
