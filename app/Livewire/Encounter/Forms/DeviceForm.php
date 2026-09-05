<?php

declare(strict_types=1);

namespace App\Livewire\Encounter\Forms;

use App\Enums\Device\Status as DeviceStatus;
use App\Enums\DeviceAssociation\Status as DeviceAssociationStatus;
use App\Models\MedicalEvents\Sql\Device;
use App\Rules\InDictionary;
use Closure;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Form;

class DeviceForm extends Form
{
    public array $devices = [];

    /**
     * Name the fields of a device the way the form labels them.
     *
     * @return array
     */
    public function validationAttributes(): array
    {
        return collect(__('devices.attributes'))
            ->mapWithKeys(static fn (string $name, string $field): array => ["devices.*.$field" => $name])
            ->all();
    }

    protected function rules(): array
    {
        return [
            'devices' => [
                'nullable',
                'array',
                static function (string $attribute, mixed $value, Closure $fail): void {
                    if ($value && Auth::user()->cannot('create', Device::class)) {
                        $fail(__('devices.policy.create'));
                    }
                }
            ],
            'devices.*' => [
                'array',
                function (string $attribute, mixed $value, Closure $fail): void {
                    // A device is registered to be worn or implanted, so the package says which of the two it is
                    $isAssociated = collect($this->component->deviceAssociationForm->deviceAssociations)->contains(
                        static fn (array $association): bool => ($association['deviceId'] ?? '') === ($value['uuid'] ?? '')
                            && in_array(
                                $association['status'] ?? '',
                                [
                                    DeviceAssociationStatus::IMPLANTED->value,
                                    DeviceAssociationStatus::ATTACHED->value
                                ],
                                true
                            )
                    );

                    if (!$isAssociated) {
                        $fail(__('devices.validation.association_required'));
                    }
                }
            ],
            // for edit page
            'devices.*.uuid' => ['nullable', 'uuid'],
            'devices.*.status' => [
                'required_with:devices',
                Rule::in([DeviceStatus::ACTIVE->value, DeviceStatus::INACTIVE->value])
            ],
            'devices.*.typeCode' => [
                'required_with:devices',
                'string',
                static function (string $attribute, mixed $value, Closure $fail): void {
                    $isAllowed = dictionary()->basics()
                        ->byName('device_definition_classification_type')
                        ->contains(
                            static fn (array $classificationType): bool => (string) $classificationType['code'] === $value
                                && $classificationType['is_active']
                        );

                    if (!$isAllowed) {
                        $fail(__('devices.validation.type_not_allowed'));
                    }
                }
            ],
            'devices.*.names' => [
                'required_with:devices',
                'array',
                'min:1',
                static function (string $attribute, mixed $value, Closure $fail): void {
                    $types = array_column((array) $value, 'type');

                    if (count($types) !== count(array_unique($types))) {
                        $fail(__('devices.validation.duplicated_name_type'));
                    }
                }
            ],
            'devices.*.names.*.type' => ['required', 'string', new InDictionary('device_name_type')],
            'devices.*.names.*.value' => ['required', 'string', 'max:255'],
            'devices.*.modelNumber' => ['nullable', 'string', 'max:255'],
            'devices.*.lotNumber' => ['nullable', 'string', 'max:255'],
            'devices.*.manufacturer' => ['nullable', 'string', 'max:255'],
            'devices.*.serialNumber' => ['nullable', 'string', 'max:255'],
            'devices.*.manufactureDate' => ['nullable', 'date', 'before:tomorrow'],
            'devices.*.expirationDate' => ['nullable', 'date'],
            'devices.*.note' => ['nullable', 'string'],
            'devices.*.primarySource' => ['required_with:devices', 'boolean'],
            'devices.*.reportOriginCode' => Rule::forEach(function (mixed $value, string $attribute) {
                $primarySource = $this->devices[(int) explode('.', $attribute)[1]]['primarySource'];

                return [
                    Rule::requiredIf($primarySource === false),
                    $primarySource === true ? 'prohibited' : 'nullable',
                    'string',
                    new InDictionary('eHealth/report_origins')
                ];
            }),
            'devices.*.reportOriginText' => ['nullable', 'string'],
            'devices.*.properties' => ['nullable', 'array'],
            'devices.*.properties.*' => [
                'array',
                static function (string $attribute, mixed $value, Closure $fail): void {
                    $provided = array_filter(
                        [
                            'valueCodeableConceptCode',
                            'valueQuantityValue',
                            'valueRangeLowValue',
                            'valueBoolean',
                            'valueInteger',
                            'valueString'
                        ],
                        static fn (string $key): bool => ($value[$key] ?? null) !== null
                    );

                    if ($provided === []) {
                        $fail(__('devices.validation.property_value_required'));

                        return;
                    }

                    if (count($provided) > 1) {
                        $fail(__('devices.validation.property_single_value'));
                    }
                }
            ],
            'devices.*.properties.*.code' => [
                'required',
                'string',
                new InDictionary('device_properties')
            ],
            'devices.*.properties.*.valueCodeableConceptSystem' => [
                'nullable',
                'required_with:devices.*.properties.*.valueCodeableConceptCode',
                'string'
            ],
            'devices.*.properties.*.valueCodeableConceptCode' => ['nullable', 'string'],
            'devices.*.properties.*.valueQuantityValue' => ['nullable', 'numeric'],
            'devices.*.properties.*.valueQuantityComparator' => [
                'nullable',
                'string',
                Rule::in(['>', '>=', '=', '<=', '<'])
            ],
            'devices.*.properties.*.valueQuantityUnit' => [
                'nullable',
                'required_with:devices.*.properties.*.valueQuantityValue',
                'string',
                new InDictionary('eHealth/ucum/units')
            ],
            'devices.*.properties.*.valueQuantitySystem' => ['nullable', 'string'],
            'devices.*.properties.*.valueQuantityCode' => ['nullable', 'string'],
            'devices.*.properties.*.valueRangeLowValue' => ['nullable', 'numeric'],
            'devices.*.properties.*.valueRangeLowUnit' => [
                'nullable',
                'required_with:devices.*.properties.*.valueRangeLowValue',
                'string',
                new InDictionary('eHealth/ucum/units')
            ],
            'devices.*.properties.*.valueRangeLowSystem' => ['nullable', 'string'],
            'devices.*.properties.*.valueRangeLowCode' => ['nullable', 'string'],
            'devices.*.properties.*.valueRangeHighValue' => [
                'nullable',
                'required_with:devices.*.properties.*.valueRangeLowValue',
                'numeric',
                'gte:devices.*.properties.*.valueRangeLowValue'
            ],
            'devices.*.properties.*.valueRangeHighUnit' => [
                'nullable',
                'required_with:devices.*.properties.*.valueRangeLowValue',
                'string',
                new InDictionary('eHealth/ucum/units')
            ],
            'devices.*.properties.*.valueRangeHighSystem' => ['nullable', 'string'],
            'devices.*.properties.*.valueRangeHighCode' => ['nullable', 'string'],
            'devices.*.properties.*.valueBoolean' => ['nullable', 'boolean'],
            'devices.*.properties.*.valueInteger' => ['nullable', 'integer'],
            'devices.*.properties.*.valueString' => ['nullable', 'string', 'max:255'],
            'devices.*.definitionId' => [
                'nullable',
                'uuid',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (!$value) {
                        return;
                    }

                    $deviceDefinition = dictionary()->deviceDefinitions()->firstWhere('id', $value);
                    $typeCode = $this->devices[(int) explode('.', $attribute)[1]]['typeCode'] ?? '';

                    $matchesType = collect($deviceDefinition['classification_types'] ?? [])->contains(
                        static fn (array $classificationType): bool => $classificationType['system'] === 'device_definition_classification_type'
                            && (string) $classificationType['code'] === $typeCode
                    );

                    if (!$matchesType) {
                        $fail(__('devices.validation.definition_type_mismatch'));
                    }
                }
            ],
            'devices.*.parentId' => ['nullable', 'uuid'],
            'devices.*.identifiers' => ['nullable', 'array'],
            // The form always shows one identifier row, so a row left empty is no identifier at all
            'devices.*.identifiers.*.code' => Rule::forEach(function (mixed $value, string $attribute): array {
                $parts = explode('.', $attribute);
                $identifier = $this->devices[(int)$parts[1]]['identifiers'][(int)$parts[3]] ?? [];

                return empty($identifier['value'])
                    ? []
                    : ['required', 'string', new InDictionary('external_system')];
            }),
            'devices.*.identifiers.*.text' => ['nullable', 'string', 'max:255'],
            'devices.*.identifiers.*.value' => Rule::forEach(function (mixed $value, string $attribute): array {
                $parts = explode('.', $attribute);
                $identifier = $this->devices[(int)$parts[1]]['identifiers'][(int)$parts[3]] ?? [];

                return [
                    Rule::requiredIf(!empty($identifier['code'])),
                    'nullable',
                    'string',
                    'max:255'
                ];
            })
        ];
    }
}
