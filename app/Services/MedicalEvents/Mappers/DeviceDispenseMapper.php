<?php

declare(strict_types=1);

namespace App\Services\MedicalEvents\Mappers;

use App\Contracts\FhirMapperContract;
use App\Enums\DeviceDispense\DeviceReferenceType;
use App\Services\MedicalEvents\FhirResource;
use Illuminate\Support\Str;

class DeviceDispenseMapper implements FhirMapperContract
{
    /**
     * Convert a flat form device dispense to the FHIR structure the Encounter Package carries (TV 3.22.2.1).
     *
     * @param  array  $data  Flat device dispense form data
     * @param  mixed  ...$context  [0] array $uuids  Shared UUIDs (encounter, employee, etc.)
     * @return array
     */
    public function toFhir(array $data, mixed ...$context): array
    {
        [$uuids] = $context;

        $result = [
            'id' => $data['uuid'] ?? Str::uuid()->toString(),
            // The dispense is recorded within the encounter it belongs to, so that encounter is its context
            'encounter' => FhirResource::make()
                ->coding('eHealth/resources', 'encounter')
                ->toIdentifier($uuids['encounter']),
            'performer' => FhirResource::make()
                ->coding('eHealth/resources', 'employee')
                ->toIdentifier($data['performerId']),
            'location' => FhirResource::make()
                ->coding('eHealth/resources', 'division')
                ->toIdentifier($data['locationId']),
            'whenHandedOver' => convertToEHealthISO8601(
                trim(($data['whenHandedOverDate'] ?? '') . ' ' . ($data['whenHandedOverTime'] ?? ''))
            ),
            'details' => $this->detailsToFhir($data)
        ];

        if (!empty($data['basedOnId'])) {
            $result['basedOn'] = [
                FhirResource::make()
                    ->coding('eHealth/resources', 'device_request')
                    ->toIdentifier($data['basedOnId'])
            ];
        }

        if (!empty($data['partOfId'])) {
            $result['partOf'] = FhirResource::make()
                ->coding('eHealth/resources', 'procedure')
                ->toIdentifier($data['partOfId']);
        }

        $supportingInfo = $this->supportingInfoToFhir($data['supportingInfo'] ?? []);

        if ($supportingInfo) {
            $result['supportingInfo'] = $supportingInfo;
        }

        return $result;
    }

    /**
     * Convert a FHIR device dispense (from DB) back to the flat structure the form holds.
     *
     * @param  array  $data  FHIR device dispense data
     * @param  mixed  ...$context  [0] array $detailsMap  Labels for the records the dispense points at
     * @return array
     */
    public function fromFhir(array $data, mixed ...$context): array
    {
        $detailsMap = $context[0] ?? [];
        $whenHandedOver = (string) data_get($data, 'whenHandedOver', '');

        // A stored dispense keeps the device it named in a column of its own, so the two representations
        // are read side by side rather than out of the `details` the payload carries them in
        $deviceDefinitionId = (string) data_get($data, 'deviceDefinition.identifier.value', '');
        $deviceTypeCode = (string) data_get($data, 'deviceCode.coding.0.code', '');

        return [
            'uuid' => data_get($data, 'uuid'),
            'basedOnId' => (string) data_get($data, 'basedOn.identifier.value', ''),
            'partOfId' => (string) data_get($data, 'partOf.identifier.value', ''),
            'performerId' => (string) data_get($data, 'performer.identifier.value', ''),
            'locationId' => (string) data_get($data, 'location.identifier.value', ''),
            'whenHandedOverDate' => convertToAppDateFormat(Str::before($whenHandedOver, ' ')),
            'whenHandedOverTime' => Str::of($whenHandedOver)->after(' ')->substr(0, 5)->toString(),
            'deviceReferenceType' => $deviceDefinitionId !== ''
                ? DeviceReferenceType::DEVICE_DEFINITION->value
                : DeviceReferenceType::DEVICE_CODE->value,
            'deviceTypeCode' => $deviceTypeCode,
            'deviceDefinitionId' => $deviceDefinitionId,
            'quantity' => data_get($data, 'quantity'),
            'supportingInfo' => collect(data_get($data, 'supportingInfo', []))
                ->map(static function (array $reference) use ($detailsMap): array {
                    $uuid = (string) data_get($reference, 'identifier.value', '');

                    return [
                        'uuid' => $uuid,
                        'type' => (string) data_get($reference, 'identifier.type.coding.0.code', ''),
                        'displayValue' => (string) ($detailsMap[$uuid] ?? data_get($reference, 'display_value', ''))
                    ];
                })
                ->values()
                ->toArray()
        ];
    }

    /**
     * Build `details`, which names the dispensed device either by its type or by its model, never by both.
     *
     * A request written for a device type may be dispensed as a type, a model or a brand (TV 3.22.1.4.1);
     * a request written for a model may only be dispensed as a model or a brand (TV 3.22.1.4.2). Both a model
     * and a brand are device definitions in eHealth, so they travel in the same `device[].device_definition`.
     *
     * @param  array  $data
     * @return array
     */
    private function detailsToFhir(array $data): array
    {
        $details = ['quantity' => (int) $data['quantity']];

        if (($data['deviceReferenceType'] ?? '') === DeviceReferenceType::DEVICE_DEFINITION->value) {
            $details['device'] = [[
                'deviceDefinition' => FhirResource::make()
                    ->coding('eHealth/resources', 'device_definition')
                    ->toIdentifier($data['deviceDefinitionId'])
            ]];

            return $details;
        }

        $details['deviceCode'] = [[
            'code' => FhirResource::make()
                ->coding('device_definition_classification_type', (string) $data['deviceTypeCode'])
                ->toCodeableConcept()
        ]];

        return $details;
    }

    /**
     * Build `supporting_info` out of the records the user pointed at.
     *
     * TV 3.22.2.1 asks for references, so a free-text note is deliberately not accepted here.
     *
     * @param  array  $supportingInfo
     * @return array
     */
    private function supportingInfoToFhir(array $supportingInfo): array
    {
        return collect($supportingInfo)
            ->filter(static fn (array $reference): bool => !empty($reference['uuid']) && !empty($reference['type']))
            ->map(
                static fn (array $reference): array => FhirResource::make()
                    ->coding('eHealth/resources', $reference['type'])
                    ->toIdentifier($reference['uuid'])
            )
            ->values()
            ->toArray();
    }
}
