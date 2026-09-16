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

                    // Encounter Package: "Device request with program can not be referenced".
                    if (
                        !$deviceRequest
                        || strtolower((string) ($deviceRequest['status'] ?? '')) !== 'active'
                        || ($deviceRequest['intent'] ?? null) !== 'order'
                        || !empty($deviceRequest['programId'])
                    ) {
                        $fail(__('device-dispenses.validation.device_request_not_available'));
                    }
                },
            ],
            'deviceDispenses.*.quantityCode' => ['required_with:deviceDispenses', 'string'],
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
                },
            ],
            'deviceDispenses.*.performerId' => [
                'required_with:deviceDispenses',
                'uuid',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (($this->component->deviceDispenseEmployee['uuid'] ?? null) !== $value) {
                        $fail(__('device-dispenses.validation.employee_not_found'));
                    }
                },
            ],
            'deviceDispenses.*.locationId' => [
                'required_with:deviceDispenses',
                'uuid',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (!collect($this->component->divisions)->contains('uuid', $value)) {
                        $fail(__('device-dispenses.validation.division_not_found'));
                    }
                },
            ],
            'deviceDispenses.*.whenHandedOverDate' => [
                'required_with:deviceDispenses',
                'date',
                'date_equals:' . (($this->component->form->encounter['periodDate'] ?? '') ?: 'today'),
            ],
            'deviceDispenses.*.whenHandedOverTime' => ['required_with:deviceDispenses', 'date_format:H:i'],
            'deviceDispenses.*.quantity' => Rule::forEach(
                function (mixed $value, string $attribute): array {
                    $index = (int) explode('.', $attribute)[1];

                    return [
                        'required_with:deviceDispenses',
                        'integer',
                        'min:1',
                        function (string $attribute, mixed $value, Closure $fail) use ($index): void {
                            $basedOnId = $this->deviceDispenses[$index]['basedOnId'] ?? null;

                            if (!$basedOnId) {
                                return;
                            }

                            $deviceRequest = collect($this->component->deviceRequests)
                                ->firstWhere('uuid', $basedOnId);

                            if (!$deviceRequest) {
                                return;
                            }

                            $remainingQuantity = $deviceRequest['remainingQuantity'] ?? null;

                            if ($remainingQuantity === null) {
                                return;
                            }

                            $otherQuantity = collect($this->deviceDispenses)
                                ->filter(
                                    static fn (array $deviceDispense, int $deviceDispenseIndex): bool =>
                                        $deviceDispenseIndex !== $index
                                        && ($deviceDispense['basedOnId'] ?? null) === $basedOnId
                                )
                                ->sum(static fn (array $deviceDispense): int => (int) ($deviceDispense['quantity'] ?? 0));

                            if ($otherQuantity + (int) $value > (int) $remainingQuantity) {
                                $fail(__('device-dispenses.validation.quantity_exceeds_remaining'));
                            }
                        },
                    ];
                }
            ),
            'deviceDispenses.*.deviceSelectionType' => [
                'required_with:deviceDispenses',
                Rule::in(['type', 'model']),
            ],
            'deviceDispenses.*.deviceCode' => Rule::forEach(function (mixed $value, string $attribute): array {
                $index = (int) explode('.', $attribute)[1];
                $type = $this->deviceDispenses[$index]['deviceSelectionType'] ?? '';

                return [
                    Rule::requiredIf($type === 'type'),
                    $type === 'model' ? 'prohibited' : 'nullable',
                    'string',
                    new InDictionary('device_definition_classification_type'),
                    function (string $attribute, mixed $value, Closure $fail) use ($index): void {
                        if (!$value) {
                            return;
                        }

                        $basedOnId = $this->deviceDispenses[$index]['basedOnId'] ?? null;

                        if (!$basedOnId) {
                            return;
                        }

                        $deviceRequest = collect($this->component->deviceRequests)
                            ->firstWhere('uuid', $basedOnId);

                        if (
                            !$deviceRequest
                            || ($deviceRequest['deviceSelectionType'] ?? '') !== 'type'
                            || ($deviceRequest['deviceId'] ?? null) !== $value
                        ) {
                            $fail(__('device-dispenses.validation.device_not_matching_request'));
                        }
                    },
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
                        },
                        function (string $attribute, mixed $value, Closure $fail): void {
                            if (!$value) {
                                return;
                            }

                            $index = (int) explode('.', $attribute)[1];
                            $basedOnId = $this->deviceDispenses[$index]['basedOnId'] ?? null;

                            if (!$basedOnId) {
                                return;
                            }

                            $deviceRequest = collect($this->component->deviceRequests)->firstWhere('uuid', $basedOnId);

                            if (!$deviceRequest) {
                                $fail(__('device-dispenses.validation.device_not_matching_request'));

                                return;
                            }

                            if (($deviceRequest['deviceSelectionType'] ?? '') === 'model') {
                                if (($deviceRequest['deviceId'] ?? null) !== $value) {
                                    $fail(__('device-dispenses.validation.device_not_matching_request'));
                                }

                                return;
                            }

                            $deviceDefinition = collect($this->component->dictionaries['custom/device_definitions'])
                                ->firstWhere('id', $value);

                            if (
                                !$deviceDefinition
                                || !in_array($deviceRequest['deviceId'] ?? '', $deviceDefinition['typeCodes'] ?? [], true)
                            ) {
                                $fail(__('device-dispenses.validation.device_not_matching_request'));
                            }
                        },
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
                    'episode',
                ]),
            ],
            'deviceDispenses.*.status' => [
                'nullable',
                Rule::in([Status::COMPLETED->value]),
            ],
        ];
    }
}
