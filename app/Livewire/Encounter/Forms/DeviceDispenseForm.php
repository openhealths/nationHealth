<?php

declare(strict_types=1);

namespace App\Livewire\Encounter\Forms;

use App\Enums\DeviceDispense\DeviceReferenceType;
use App\Enums\DeviceDispense\SupportingInfoType;
use App\Models\Employee\Employee;
use App\Models\MedicalEvents\Sql\DeviceDispense;
use App\Rules\InDictionary;
use App\Services\MedicalEvents\DeviceDispenseRules;
use App\Services\MedicalEvents\DeviceDispenseSourceRequest;
use App\Services\MedicalEvents\DeviceDispenseSourceRequestResolver;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Form;

class DeviceDispenseForm extends Form
{
    public array $deviceDispenses = [];

    /**
     * Name the fields of a dispense the way the form labels them.
     *
     * @return array
     */
    public function validationAttributes(): array
    {
        return collect(__('device-dispenses.attributes'))
            ->mapWithKeys(static fn (string $name, string $field): array => ["deviceDispenses.*.$field" => $name])
            ->all();
    }

    protected function rules(): array
    {
        return [
            'deviceDispenses' => [
                'nullable',
                'array',
                static function (string $attribute, mixed $value, Closure $fail): void {
                    if ($value && Auth::user()->cannot('create', DeviceDispense::class)) {
                        $fail(__('device-dispenses.policy.create'));
                    }
                }
            ],
            // for edit page
            'deviceDispenses.*.uuid' => ['nullable', 'uuid'],

            // TV 3.22.2.1 — the device request the dispense is issued against, when it names one
            'deviceDispenses.*.basedOnId' => Rule::forEach(
                fn (mixed $value, string $attribute): array => [
                    'nullable',
                    'uuid',
                    function (string $attribute, mixed $value, Closure $fail): void {
                        if ($this->sourceRequest($value) === null) {
                            $fail(__('device-dispenses.validation.based_on_not_available'));
                        }
                    }
                ]
            ),

            // TV 3.22.2.1 — the procedure the dispense is part of, when it names one
            'deviceDispenses.*.partOfId' => ['nullable', 'uuid'],

            // TV 3.22.2.1 — the employee who handed the devices over
            'deviceDispenses.*.performerId' => [
                'required_with:deviceDispenses',
                'uuid',
                static function (string $attribute, mixed $value, Closure $fail): void {
                    $belongsToLegalEntity = Employee::query()
                        ->whereUuid($value)
                        ->whereLegalEntityId(legalEntity()->id)
                        ->active()
                        ->exists();

                    if (!$belongsToLegalEntity) {
                        $fail(__('device-dispenses.validation.performer_wrong_legal_entity'));
                    }
                }
            ],

            // TV 3.22.2.1 — the division the devices were handed over in
            'deviceDispenses.*.locationId' => [
                'required_with:deviceDispenses',
                'uuid',
                function (string $attribute, mixed $value, Closure $fail): void {
                    $isOwnDivision = collect($this->component->divisions)->contains(
                        static fn (array $division): bool => ($division['uuid'] ?? '') === $value
                    );

                    if (!$isOwnDivision) {
                        $fail(__('device-dispenses.validation.location_wrong_legal_entity'));
                    }
                }
            ],

            // TV 3.22.2.1 — the moment the devices were handed over, which cannot be in the future
            'deviceDispenses.*.whenHandedOverDate' => [
                'required_with:deviceDispenses',
                'date',
                'before_or_equal:today'
            ],
            'deviceDispenses.*.whenHandedOverTime' => [
                'required_with:deviceDispenses',
                'date_format:H:i',
                function (string $attribute, mixed $value, Closure $fail): void {
                    $dispense = $this->deviceDispenses[(int) explode('.', $attribute)[1]] ?? [];
                    $date = $dispense['whenHandedOverDate'] ?? '';

                    if ($date === '' || $value === '') {
                        return;
                    }

                    if (CarbonImmutable::parse("$date $value")->isFuture()) {
                        $fail(__('device-dispenses.validation.when_handed_over_in_future'));
                    }
                }
            ],

            // TV 3.22.1.4 — the dispense names the device either by its type or by its model
            'deviceDispenses.*.deviceReferenceType' => Rule::forEach(
                fn (mixed $value, string $attribute): array => [
                    'required_with:deviceDispenses',
                    Rule::in(DeviceReferenceType::values()),
                    function (string $attribute, mixed $value, Closure $fail): void {
                        $dispense = $this->deviceDispenses[(int) explode('.', $attribute)[1]] ?? [];
                        $error = $this->dispenseRules()->checkDeviceReference(
                            DeviceReferenceType::from($value),
                            $this->sourceRequest($dispense['basedOnId'] ?? null)
                        );

                        if ($error !== null) {
                            $fail(__($error[0], $error[1]));
                        }
                    }
                ]
            ),

            // TV 3.22.1.4.1 — the classification type of the device, used only when a type is dispensed
            'deviceDispenses.*.deviceTypeCode' => Rule::forEach(function (mixed $value, string $attribute): array {
                $namesType = ($this->deviceDispenses[(int) explode('.', $attribute)[1]]['deviceReferenceType'] ?? '')
                    === DeviceReferenceType::DEVICE_CODE->value;

                return [
                    Rule::requiredIf($namesType),
                    $namesType ? 'string' : 'prohibited',
                    ...($namesType ? [new InDictionary('device_definition_classification_type')] : [])
                ];
            }),

            // TV 3.22.1.4.2, 3.22.1.5 — the device definition handed over, checked against the program
            'deviceDispenses.*.deviceDefinitionId' => Rule::forEach(
                function (mixed $value, string $attribute): array {
                    $dispense = $this->deviceDispenses[(int) explode('.', $attribute)[1]] ?? [];
                    $namesDefinition = ($dispense['deviceReferenceType'] ?? '')
                        === DeviceReferenceType::DEVICE_DEFINITION->value;

                    return [
                        Rule::requiredIf($namesDefinition),
                        $namesDefinition ? 'uuid' : 'prohibited',
                        function (string $attribute, mixed $value, Closure $fail) use ($namesDefinition): void {
                            if (!$namesDefinition || empty($value)) {
                                return;
                            }

                            $dispense = $this->deviceDispenses[(int) explode('.', $attribute)[1]] ?? [];
                            $error = $this->dispenseRules()->checkProgramParticipation(
                                DeviceReferenceType::DEVICE_DEFINITION,
                                (string) $value,
                                $this->sourceRequest($dispense['basedOnId'] ?? null)
                            );

                            if ($error !== null) {
                                $fail(__($error[0], $error[1]));
                            }
                        }
                    ];
                }
            ),

            // TV 3.22.2.2 — an integer greater than zero, further capped by TV 3.22.1.1 - 3.22.1.3
            'deviceDispenses.*.quantity' => [
                'required_with:deviceDispenses',
                'integer',
                'min:1',
                function (string $attribute, mixed $value, Closure $fail): void {
                    $dispense = $this->deviceDispenses[(int) explode('.', $attribute)[1]] ?? [];
                    $error = $this->dispenseRules()->checkQuantity(
                        (int) $value,
                        $this->sourceRequest($dispense['basedOnId'] ?? null),
                        legalEntity()
                    );

                    if ($error !== null) {
                        $fail(__($error[0], $error[1]));
                    }
                }
            ],

            // TV 3.22.2.1 — supporting info is a list of references, never free text
            'deviceDispenses.*.supportingInfo' => ['nullable', 'array'],
            'deviceDispenses.*.supportingInfo.*.uuid' => ['required', 'uuid'],
            'deviceDispenses.*.supportingInfo.*.type' => [
                'required',
                Rule::in(SupportingInfoType::values())
            ],
            'deviceDispenses.*.supportingInfo.*.displayValue' => ['nullable', 'string', 'max:255']
        ];
    }

    /**
     * The messages the default wording would leave without an instruction of what to do about it.
     *
     * @return array
     */
    protected function messages(): array
    {
        return [
            'deviceDispenses.*.deviceTypeCode.prohibited'
                => __('device-dispenses.validation.device_type_prohibited'),
            'deviceDispenses.*.deviceDefinitionId.prohibited'
                => __('device-dispenses.validation.device_definition_prohibited'),
            'deviceDispenses.*.quantity.min'
                => __('device-dispenses.validation.quantity_positive_integer'),
            'deviceDispenses.*.quantity.integer'
                => __('device-dispenses.validation.quantity_positive_integer')
        ];
    }

    /**
     * The device request a dispense is issued against, or null when it names none.
     *
     * @param  string|null  $requestUuid
     * @return DeviceDispenseSourceRequest|null
     */
    private function sourceRequest(?string $requestUuid): ?DeviceDispenseSourceRequest
    {
        if (empty($requestUuid)) {
            return null;
        }

        return app(DeviceDispenseSourceRequestResolver::class)
            ->resolve((string) $this->component->patientUuid, $requestUuid);
    }

    private function dispenseRules(): DeviceDispenseRules
    {
        return app(DeviceDispenseRules::class);
    }
}
