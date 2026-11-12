<?php

declare(strict_types=1);

namespace App\Livewire\CarePlan\Forms;

use App\Core\Arr;
use Illuminate\Validation\Rule;
use Livewire\Form;

class CarePlanReferralForm extends Form
{
    public string|int|null $activity_id = '';
    public string|null $category = '';
    public string|null $category_label = '';
    public string|null $code = '';
    public string|null $code_label = '';
    public string|null $end_time = '';
    public string|null $ended_at = '';
    public string|null $inform_with = '';
    public string|null $intent = '';
    public string|null $kind = '';
    public string|null $note = '';
    public string|null $patient_instruction = '';
    public string|null $priority = '';
    public string|null $program_id = '';
    public string|int|float|null $quantity = '';
    public string|null $reason_reference = '';
    public string|null $start_time = '';
    public string|null $started_at = '';
    public array $supporting_info = [];

    public function rulesForReferral(array $priorities, array $categories, bool $isServiceGroup): array
    {
        $rules = [
            'started_at' => 'required|date_format:d.m.Y',
            'ended_at' => 'required|date_format:d.m.Y',
            'start_time' => 'required|date_format:H:i',
            'end_time' => 'required|date_format:H:i',
            'quantity' => 'required|numeric|min:0.01',
            'priority' => ['required', Rule::in($priorities)],
        ];

        if ($this->kind === 'service_request') {
            $rules['category'] = $isServiceGroup
                ? ['required', 'string', Rule::in($categories)]
                : 'required|string';
        }

        return $rules;
    }

    protected function validationAttributes(): array
    {
        $attributes = __('validation.attributes');
        $result = [];
        foreach (['category', 'started_at', 'ended_at', 'start_time', 'end_time', 'quantity', 'priority'] as $field) {
            $result[$field] = Arr::get($attributes, 'referralForm.'.$field, $field);
        }

        return $result;
    }
}
