<?php

declare(strict_types=1);

namespace App\Livewire\Equipment\Forms;

use App\Enums\Equipment\{AvailabilityStatus, Status};
use App\Enums\Status as BaseStatus;
use App\Rules\InDictionary;
use Illuminate\Database\Query\Builder;
use Illuminate\Validation\Rule;
use Livewire\Form;

class EquipmentForm extends Form
{
    public array $names = [['name' => '', 'type' => '']];
    public string|null $type = null;
    public ?string $serialNumber = null;
    public string $status;
    public string $recorder;
    public ?string $divisionId = null;
    public string $availabilityStatus;
    public ?string $inventoryNumber = null;
    public ?string $manufacturer = null;
    public ?string $manufactureDate = null;
    public ?string $expirationDate = null;
    public ?string $modelNumber = null;
    public ?string $lotNumber = null;
    public ?string $note = null;

    public ?string $parentId = null;
    public ?string $deviceDefinitionId = null;
    public ?array $properties = null;
    public ?string $uuid = null;
    public ?string $errorReason = null;

    /**
     * Rules based on: https://e-health-ua.atlassian.net/wiki/spaces/ESOZ/pages/17571807355/REST+API+Create+equipment+API-007-028-0001#Request-data-validation
     *
     * @return array
     */
    public function rules(): array
    {
        $requiredTypes = config('ehealth.equipment.types_with_required_serial_number', []);

        return [
            'names' => ['required', 'array', 'min:1'],
            'names.*.name' => ['required', 'string', 'max:255'],
            'names.*.type' => ['required', 'string', 'max:255', new InDictionary('device_name_type'), 'distinct'],
            'type' => [
                'required',
                'string',
                'max:255',
                new InDictionary('device_definition_classification_type')
            ],
            'serialNumber' => [
                'nullable',
                'string',
                'max:255',
                Rule::requiredIf(fn () => in_array($this->type, $requiredTypes, true)),
            ],
            'status' => ['required', 'string', Rule::in(Status::ACTIVE)],
            'recorder' => ['required', 'uuid', Rule::in(array_column($this->component->recorders, 'uuid'))],
            'divisionId' => [
                'nullable',
                'uuid',
                Rule::exists('divisions', 'uuid')->where('status', BaseStatus::ACTIVE)
            ],
            'availabilityStatus' => ['required', 'string', Rule::in(AvailabilityStatus::AVAILABLE)],
            'inventoryNumber' => [
                'nullable',
                'string',
                'max:255',
                Rule::unique('equipments', 'inventory_number')
                    ->where(fn (Builder $query) => $query->where('legal_entity_id', legalEntity()->id)
                        ->whereNot('status', Status::INACTIVE))
                    ->ignore($this->component->equipmentId)
            ],
            'manufacturer' => ['nullable', 'string', 'max:255'],
            'manufactureDate' => ['nullable', Rule::date()->beforeOrEqual(today())],
            'expirationDate' => ['nullable', 'date_format:' . config('app.date_format')],
            'modelNumber' => ['nullable', 'string', 'max:255'],
            'lotNumber' => ['nullable', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:1000'],
            'parentId' => [
                'nullable',
                'uuid',
                Rule::exists('equipments', 'uuid')->where('status', Status::ACTIVE)
            ],
            'properties' => ['nullable', 'array'],
            'properties.*.type' => ['required', 'string', 'max:255', new InDictionary('device_properties')],
            'properties.*.valueInteger' => [
                'nullable',
                'required_without_all:properties.*.valueDecimal,properties.*.valueBoolean,properties.*.valueString',
                'prohibits:properties.*.valueDecimal,properties.*.valueBoolean,properties.*.valueString',
                'integer:strict'
            ],
            'properties.*.valueDecimal' => [
                'nullable',
                'required_without_all:properties.*.valueInteger,properties.*.valueBoolean,properties.*.valueString',
                'prohibits:properties.*.valueInteger,properties.*.valueBoolean,properties.*.valueString',
                'numeric'
            ],
            'properties.*.valueBoolean' => [
                'nullable',
                'required_without_all:properties.*.valueInteger,properties.*.valueDecimal,properties.*.valueString',
                'prohibits:properties.*.valueInteger,properties.*.valueDecimal,properties.*.valueString',
                'boolean:strict'
            ],
            'properties.*.valueString' => [
                'nullable',
                'required_without_all:properties.*.valueInteger,properties.*.valueDecimal,properties.*.valueBoolean',
                'prohibits:properties.*.valueInteger,properties.*.valueDecimal,properties.*.valueBoolean',
                'string',
                'max:255'
            ],
            'deviceDefinitionId' => ['nullable', 'uuid'],
            // add exists: https://uaehealthapi.docs.apiary.io/#reference/public.-devices/get-device-definitions/get-device-definitions-v2
        ];
    }

    /**
     * Redefine field names for error messages.
     *
     * @return array
     */
    protected function validationAttributes(): array
    {
        return [
            'type' => __('equipments.type_medical_device'),
            'divisionId' => __('forms.division_name')
        ];
    }
}
