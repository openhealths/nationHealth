<?php

declare(strict_types=1);

namespace App\Livewire\DeviceRequest\Forms;

use App\Enums\Person\DeviceRequestStatus;
use Carbon\CarbonImmutable;
use Illuminate\Validation\Rule;
use Livewire\Form;

class DeviceRequestSearchForm extends Form
{
    public array $parameters = [
        'requester_legal_entity' => '', 'requester' => '', 'status' => '', 'code' => '', 'code_reference' => '',
        'context_episode_id' => '', 'encounter' => '', 'program' => '', 'based_on' => '', 'priority' => '', 'performer' => '',
        'authored_on_from' => '', 'authored_on_to' => '', 'occurrence_period_start_from' => '', 'occurrence_period_start_to' => '',
        'occurrence_period_end_from' => '', 'occurrence_period_end_to' => '',
    ];

    public function rules(): array
    {
        $rules = [
            'parameters.requester_legal_entity' => ['nullable', 'uuid'],
            'parameters.context_episode_id' => ['nullable', 'uuid'],
            'parameters.encounter' => ['nullable', 'uuid'],
            'parameters.program' => ['nullable', 'uuid'],
            'parameters.requester' => ['nullable', 'uuid'],
            'parameters.performer' => ['nullable', 'uuid'],
            'parameters.code_reference' => ['nullable', 'uuid'],
            'parameters.based_on' => ['nullable', 'uuid'],
            'parameters.priority' => ['nullable', 'string', 'max:100'],
            'parameters.status' => ['nullable', Rule::in([
                DeviceRequestStatus::ACTIVE->value,
                DeviceRequestStatus::COMPLETED->value,
                DeviceRequestStatus::REVOKED->value,
                DeviceRequestStatus::ENTERED_IN_ERROR->value,
            ])],
            'parameters.code' => ['nullable', 'string', 'max:100'],
        ];

        foreach (['authored_on', 'occurrence_period_start', 'occurrence_period_end'] as $date) {
            $rules['parameters.'.$date.'_from'] = ['nullable', 'date'];
            $rules['parameters.'.$date.'_to'] = ['nullable', 'date'];
            if (!empty($this->parameters[$date.'_from'])) {
                $rules['parameters.'.$date.'_to'][] = 'after_or_equal:parameters.'.$date.'_from';
            }
        }

        return $rules;
    }

    public function toQuery(): array
    {
        $validated = $this->validate();
        $filters = array_intersect_key($validated['parameters'], array_flip(array_map(
            static fn (string $field): string => substr($field, strlen('parameters.')),
            array_keys($this->rules())
        )));

        foreach (['authored_on', 'occurrence_period_start', 'occurrence_period_end'] as $date) {
            foreach (['from', 'to'] as $bound) {
                $key = $date.'_'.$bound;
                if (!empty($filters[$key])) {
                    $filters[$key] = CarbonImmutable::parse($filters[$key])->toIso8601String();
                }
            }
        }

        return array_filter($filters, static fn ($value): bool => $value !== '' && $value !== null);
    }
}
