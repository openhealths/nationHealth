<?php

declare(strict_types=1);

namespace App\Livewire\Encounter\Forms;

use App\Enums\DeviceDispense\Status;
use App\Rules\InDictionary;
use Closure;
use Illuminate\Validation\Rule;
use Livewire\Form;

class DeviceDispenseForm extends Form
{
    public array $deviceDispenses = [];

    protected function rules(): array
    {
        return [
            'deviceDispenses' => ['nullable', 'array'],
            'deviceDispenses.*.uuid' => ['nullable', 'uuid'],
            'deviceDispenses.*.basedOnId' => [
                'nullable',
                'uuid',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (!$value) {
                        return;
                    }

                    $deviceRequest = collect($this->component->deviceRequests)->firstWhere('uuid', $value);

                    if (!$deviceRequest || strtolower((string) $deviceRequest['status']) !== 'active') {
                        $fail(__('device-dispenses.validation.device_request_not_available'));
                    }
                }
            ],
            'deviceDispenses.*.partOfId' => [
                'nullable',
                'uuid',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (!$value) {
                        return;
                    }

                    if (!collect($this->component->procedureForm->procedures)->contains('uuid', $value)) {
                        $fail(__('device-dispenses.validation.procedure_not_found'));
                    }
                }
            ],
            'deviceDispenses.*.performerId' => [
                'required_with:deviceDispenses',
                'uuid',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (!collect($this->component->deviceDispenseEmployees)->contains('uuid', $value)) {
                        $fail(__('device-dispenses.validation.employee_not_found'));
                    }
                }
            ],
            'deviceDispenses.*.locationId' => [
                'required_with:deviceDispenses',
                'uuid',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (!collect($this->component->divisions)->contains('uuid', $value)) {
                        $fail(__('device-dispenses.validation.division_not_found'));
                    }
                }
            ],
            'deviceDispenses.*.whenHandedOverDate' => [
                'required_with:deviceDispenses',
                'date',
                'date_equals:' . (($this->component->form->encounter['periodDate'] ?? '') ?: 'today')
            ],
            'deviceDispenses.*.whenHandedOverTime' => ['required_with:deviceDispenses', 'date_format:H:i'],
            'deviceDispenses.*.quantity' => ['required_with:deviceDispenses', 'integer', 'min:1'],
            'deviceDispenses.*.deviceSelectionType' => [
                'required_with:deviceDispenses',
                Rule::in(['type', 'model'])
            ],
            'deviceDispenses.*.deviceCode' => Rule::forEach(function (mixed $value, string $attribute): array {
                $type = $this->deviceDispenses[(int) explode('.', $attribute)[1]]['deviceSelectionType'] ?? '';

                return [
                    Rule::requiredIf($type === 'type'),
                    $type === 'model' ? 'prohibited' : 'nullable',
                    'string',
                    new InDictionary('device_definition_classification_type')
                ];
            }),
            'deviceDispenses.*.deviceDefinitionId' => Rule::forEach(
                function (mixed $value, string $attribute): array {
                    $type = $this->deviceDispenses[(int) explode('.', $attribute)[1]]['deviceSelectionType'] ?? '';

                    return [
                        Rule::requiredIf($type === 'model'),
                        $type === 'type' ? 'prohibited' : 'nullable',
                        'uuid',
                        function (string $attribute, mixed $value, Closure $fail): void {
                            if (!$value) {
                                return;
                            }

                            if (!collect($this->component->dictionaries['custom/device_definitions'])
                                ->contains('id', $value)) {
                                $fail(__('device-dispenses.validation.device_definition_not_found'));
                            }
                        }
                    ];
                }
            ),
            'deviceDispenses.*.note' => ['nullable', 'string', 'max:3000'],
            'deviceDispenses.*.supportingInfo' => ['nullable', 'array'],
            'deviceDispenses.*.supportingInfo.*.uuid' => ['required', 'uuid'],
            'deviceDispenses.*.supportingInfo.*.type' => [
                'required',
                Rule::in([
                    'diagnostic_report',
                    'observation',
                    'condition',
                    'procedure',
                    'encounter',
                    'episode'
                ])
            ],
            'deviceDispenses.*.status' => [
                'nullable',
                Rule::in([Status::COMPLETED->value])
            ]
        ];
    }
}