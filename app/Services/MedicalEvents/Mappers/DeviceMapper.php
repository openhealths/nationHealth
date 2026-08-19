<?php

declare(strict_types=1);

namespace App\Services\MedicalEvents\Mappers;

use App\Contracts\FhirMapperContract;
use App\Enums\Device\Status;
use App\Services\MedicalEvents\FhirResource;
use Illuminate\Support\Str;

class DeviceMapper implements FhirMapperContract
{
    /**
     * Convert a flat form device to a FHIR structure for persistence/API.
     *
     * @param  array  $data  Flat device form data
     * @param  mixed  ...$context  [0] array $uuids  Shared UUIDs (encounter, employee, etc.)
     * @return array
     */
    public function toFhir(array $data, mixed ...$context): array
    {
        [$uuids] = $context;

        $result = [
            'id' => $data['uuid'] ?? Str::uuid()->toString(),
            'status' => $data['status'] ?? Status::ACTIVE->value,
            'primarySource' => $data['primarySource'],
            'type' => FhirResource::make()
                ->coding('device_definition_classification_type', $data['typeCode'])
                ->toCodeableConcept(),
            'name' => collect($data['names'] ?? [])
                ->map(
                    static fn (array $name): array => [
                        'type' => $name['type'],
                        'value' => $name['value']
                    ]
                )
                ->values()
                ->toArray(),
            // The device belongs to the encounter it is recorded in, so its context is that encounter and no other
            'context' => FhirResource::make()
                ->coding('eHealth/resources', 'encounter')
                ->toIdentifier($uuids['encounter']),
            'recorder' => FhirResource::make()
                ->coding('eHealth/resources', 'employee')
                ->toIdentifier($uuids['employee'])
        ];

        $identifiers = collect($data['identifiers'] ?? [])
            ->filter(static fn (array $identifier): bool => !empty($identifier['value']))
            ->map(
                static fn (array $identifier): array => [
                    'type' => FhirResource::make()
                        ->coding('external_system', $identifier['code'])
                        ->toCodeableConcept($identifier['text'] ?? ''),
                    'value' => $identifier['value']
                ]
            )
            ->values()
            ->toArray();

        if ($identifiers) {
            $result['identifier'] = $identifiers;
        }

        $properties = collect($data['properties'] ?? [])
            ->map(fn (array $property): array => $this->propertyToFhir($property))
            ->values()
            ->toArray();

        if ($properties) {
            $result['property'] = $properties;
        }

        if (!$data['primarySource']) {
            $result['reportOrigin'] = FhirResource::make()
                ->coding('eHealth/report_origins', $data['reportOriginCode'])
                ->toCodeableConcept($data['reportOriginText'] ?? '');
        }

        if (!empty($data['modelNumber'])) {
            $result['modelNumber'] = $data['modelNumber'];
        }

        if (!empty($data['lotNumber'])) {
            $result['lotNumber'] = $data['lotNumber'];
        }

        if (!empty($data['manufacturer'])) {
            $result['manufacturer'] = $data['manufacturer'];
        }

        if (!empty($data['serialNumber'])) {
            $result['serialNumber'] = $data['serialNumber'];
        }

        if (!empty($data['manufactureDate'])) {
            $result['manufactureDate'] = convertToEHealthISO8601($data['manufactureDate']);
        }

        if (!empty($data['expirationDate'])) {
            $result['expirationDate'] = convertToEHealthISO8601($data['expirationDate']);
        }

        if (!empty($data['note'])) {
            $result['note'] = $data['note'];
        }

        if (!empty($data['definitionId'])) {
            $result['definition'] = FhirResource::make()
                ->coding('eHealth/resources', 'device_definition')
                ->toIdentifier($data['definitionId']);
        }

        if (!empty($data['parentId'])) {
            $result['parent'] = FhirResource::make()
                ->coding('eHealth/resources', 'device')
                ->toIdentifier($data['parentId']);
        }

        return $result;
    }

    /**
     * Convert a FHIR device (from DB) to a flat form structure.
     *
     * @param  array  $data  FHIR device data
     * @param  mixed  ...$context
     * @return array
     */
    public function fromFhir(array $data, mixed ...$context): array
    {
        return [
            'uuid' => data_get($data, 'uuid'),
            'status' => data_get($data, 'status', Status::ACTIVE->value),
            'typeCode' => data_get($data, 'type.coding.0.code', ''),
            'modelNumber' => data_get($data, 'modelNumber', ''),
            'lotNumber' => data_get($data, 'lotNumber', ''),
            'manufacturer' => data_get($data, 'manufacturer', ''),
            'serialNumber' => data_get($data, 'serialNumber', ''),
            'manufactureDate' => convertToAppDateFormat(data_get($data, 'manufactureDate')),
            'expirationDate' => convertToAppDateFormat(data_get($data, 'expirationDate')),
            'note' => data_get($data, 'note', ''),
            'primarySource' => data_get($data, 'primarySource'),
            'reportOriginCode' => data_get($data, 'reportOrigin.coding.0.code', ''),
            'reportOriginText' => data_get($data, 'reportOrigin.text', ''),
            'properties' => collect(data_get($data, 'properties', []))
                ->map(fn (array $property): array => $this->propertyFromFhir($property))
                ->values()
                ->toArray(),
            'names' => collect(data_get($data, 'names', []))
                ->map(
                    static fn (array $name): array => [
                        'type' => data_get($name, 'type', ''),
                        'value' => data_get($name, 'value', '')
                    ]
                )
                ->values()
                ->toArray(),
            'definitionId' => data_get($data, 'definition.identifier.value', ''),
            'parentId' => data_get($data, 'parent.identifier.value', ''),
            'identifiers' => collect(data_get($data, 'identifiers', []))
                ->map(
                    static fn (array $identifier): array => [
                        'code' => data_get($identifier, 'identifier.type.coding.0.code', ''),
                        'text' => data_get($identifier, 'identifier.type.text', ''),
                        'value' => data_get($identifier, 'identifier.value', '')
                    ]
                )
                ->values()
                ->toArray()
        ];
    }

    /**
     * Convert a flat form property to FHIR, carrying the single value the property was given.
     *
     * @param  array  $property
     * @return array
     */
    private function propertyToFhir(array $property): array
    {
        $result = [
            'code' => FhirResource::make()
                ->coding('device_properties', $property['code'])
                ->toCodeableConcept()
        ];

        if (isset($property['valueCodeableConceptCode'])) {
            $result['valueCodeableConcept'] = FhirResource::make()
                ->coding($property['valueCodeableConceptSystem'], $property['valueCodeableConceptCode'])
                ->toCodeableConcept();
        }

        if (isset($property['valueQuantityValue'])) {
            $result['valueQuantity'] = [
                'value' => $property['valueQuantityValue'],
                'comparator' => $property['valueQuantityComparator'] ?? null,
                'unit' => $property['valueQuantityUnit'],
                'system' => $property['valueQuantitySystem'] ?? null,
                'code' => $property['valueQuantityCode'] ?? null
            ];
        }

        if (isset($property['valueRangeLowValue'])) {
            $result['valueRange'] = [
                'low' => [
                    'value' => $property['valueRangeLowValue'],
                    'unit' => $property['valueRangeLowUnit'],
                    'system' => $property['valueRangeLowSystem'] ?? null,
                    'code' => $property['valueRangeLowCode'] ?? null
                ],
                'high' => [
                    'value' => $property['valueRangeHighValue'],
                    'unit' => $property['valueRangeHighUnit'],
                    'system' => $property['valueRangeHighSystem'] ?? null,
                    'code' => $property['valueRangeHighCode'] ?? null
                ]
            ];
        }

        if (isset($property['valueBoolean'])) {
            $result['valueBoolean'] = $property['valueBoolean'];
        }

        if (isset($property['valueInteger'])) {
            $result['valueInteger'] = $property['valueInteger'];
        }

        if (isset($property['valueString'])) {
            $result['valueString'] = $property['valueString'];
        }

        return $result;
    }

    /**
     * Convert a FHIR property (from DB) to a flat form structure, where the value is held under the value relation.
     *
     * @param  array  $property
     * @return array
     */
    private function propertyFromFhir(array $property): array
    {
        // A value the property was not given stays null, so that a false boolean is not read back as an absent value
        return [
            'code' => data_get($property, 'code.coding.0.code', ''),
            'valueCodeableConceptSystem' => data_get($property, 'value.valueCodeableConcept.coding.0.system'),
            'valueCodeableConceptCode' => data_get($property, 'value.valueCodeableConcept.coding.0.code'),
            'valueQuantityValue' => data_get($property, 'value.valueQuantity.value'),
            'valueQuantityComparator' => data_get($property, 'value.valueQuantity.comparator'),
            'valueQuantityUnit' => data_get($property, 'value.valueQuantity.unit'),
            'valueQuantitySystem' => data_get($property, 'value.valueQuantity.system'),
            'valueQuantityCode' => data_get($property, 'value.valueQuantity.code'),
            'valueRangeLowValue' => data_get($property, 'value.valueRange.low.value'),
            'valueRangeLowUnit' => data_get($property, 'value.valueRange.low.unit'),
            'valueRangeLowSystem' => data_get($property, 'value.valueRange.low.system'),
            'valueRangeLowCode' => data_get($property, 'value.valueRange.low.code'),
            'valueRangeHighValue' => data_get($property, 'value.valueRange.high.value'),
            'valueRangeHighUnit' => data_get($property, 'value.valueRange.high.unit'),
            'valueRangeHighSystem' => data_get($property, 'value.valueRange.high.system'),
            'valueRangeHighCode' => data_get($property, 'value.valueRange.high.code'),
            'valueBoolean' => data_get($property, 'value.valueBoolean'),
            'valueInteger' => data_get($property, 'value.valueInteger'),
            'valueString' => data_get($property, 'value.valueString')
        ];
    }
}
