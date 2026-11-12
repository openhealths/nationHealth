<?php

declare(strict_types=1);

namespace App\Livewire\CarePlan\Forms;

use App\Models\CarePlan;
use Illuminate\Validation\ValidationException;
use Livewire\Form;

class CarePlanActivityForm extends Form
{
    public string|int|float|null $daily_amount = '';
    public string|null $daily_amount_code = '';
    public string|null $daily_amount_system = '';
    public string|null $description = '';
    public string|null $goal = '';
    public string|int|null $id = null;
    public string|null $kind = 'service_request';
    public string|null $product_codeable_concept = '';
    public string|null $product_reference = '';
    public string|null $product_reference_type = '';
    public string|null $program = '';
    public string|int|float|null $quantity = '';
    public string|null $quantity_code = '';
    public string|null $quantity_system = '';
    public string|null $reason_code = '';
    public string|null $reason_reference = '';
    public string|null $scheduled_period_end = '';
    public string|null $scheduled_period_start = '';

    public function assertServiceUnit(CarePlan $carePlan, string $code): void
    {
        if ($code === 'MINUTE' && !$carePlan->allowsServiceMinutes()) {
            throw ValidationException::withMessages(['activityForm.quantity_code' => __('care-plan.service_minutes_unavailable')]);
        }
    }

    public function assertServicePeriod(CarePlan $carePlan, array $detail): void
    {
        if ($carePlan->termsOfServiceCode() !== 'OUTPATIENT') {
            return;
        }
        $errors = [];
        foreach (['start', 'end'] as $boundary) {
            if (!filled(data_get($detail, 'scheduledPeriod.'.$boundary))) {
                $errors['activityForm.scheduled_period_'.$boundary] = __('care-plan.service_activity_period_required');
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    public function rulesForDraft(bool $usesAlternativeSchedule, string $termsOfService, array $allowedCodeTypes): array
    {
        $programId = $this->program;
        $periodRule = !empty($programId) && !$usesAlternativeSchedule ? 'required|date_format:'.config('app.date_format') : 'nullable|date_format:'.config('app.date_format');

        $rules = [
            'kind' => 'required|string',
            'scheduled_period_start' => $periodRule,
            'scheduled_period_end' => $periodRule,
            'quantity' => 'nullable|numeric',
            'quantity_system' => 'nullable|string',
            'quantity_code' => 'nullable|string',
            'daily_amount' => 'nullable|numeric',
            'daily_amount_system' => 'nullable|string',
            'daily_amount_code' => 'nullable|string',
            'description' => 'nullable|string',
            'product_reference' => 'nullable|string',
            'program' => 'nullable|string',
            'reason_code' => 'nullable|string',
            'goal' => 'nullable|string',
        ];

        $kindLower = strtolower((string) $this->kind);
        if (str_contains($kindLower, 'device')) {
            $rules['quantity'] = !empty($programId) ? 'required|integer|min:1' : 'nullable|integer|min:1';
            $rules['product_reference'] = 'nullable|uuid|required_without:product_codeable_concept';

            $requiresClassificationOnly = in_array('CLASSIFICATION_TYPE', $allowedCodeTypes, true)
                && !in_array('DEVICE_DEFINITION', $allowedCodeTypes, true);

            if ($requiresClassificationOnly) {
                $rules['product_codeable_concept'] = 'required|string';
                $rules['product_reference'] = 'prohibited';
            } else {
                $rules['product_codeable_concept'] = 'nullable|string|required_without:product_reference';
            }
        }

        if (str_contains($kindLower, 'medication')) {
            $rules['program'] = 'required|uuid';
            $rules['quantity'] = 'required|numeric|gt:0';
            $rules['daily_amount'] = 'required|numeric|min:0.01';
            $rules['quantity_code'] = 'required|string';
        }

        if (str_contains($kindLower, 'service')) {
            $rules['quantity'] = 'required|integer|gt:0';
            $rules['quantity_code'] = 'required_with:quantity|in:PIECE,MINUTE';
            if ($termsOfService === 'OUTPATIENT') {
                $rules['scheduled_period_start'] = 'required|date_format:' . config('app.date_format');
                $rules['scheduled_period_end'] = 'required|date_format:' . config('app.date_format');
            }
        }

        return $rules;
    }

    protected function validationAttributes(): array
    {
        return __('validation.attributes.activityForm');
    }
}
