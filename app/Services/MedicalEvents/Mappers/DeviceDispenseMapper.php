<?php

declare(strict_types=1);

namespace App\Services\MedicalEvents\Mappers;

use App\Contracts\FhirMapperContract;
use App\Enums\DeviceDispense\Status;
use App\Services\MedicalEvents\FhirResource;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

class DeviceDispenseMapper implements FhirMapperContract
{
    public function toFhir(array $data, mixed ...$context): array
    {
        [$uuids] = $context;

        $details = [
            'quantity' => [
                'value' => (int) $data['quantity'],
                'system' => 'device_unit',
                'code' => 'piece'
            ]
        ];

        if ($data['deviceSelectionType'] === 'model') {
            $details['device'] = FhirResource::make()
                ->coding('eHealth/resources', 'device_definition')
                ->toIdentifier($data['deviceDefinitionId']);
        } else {
            $details['deviceCode'] = FhirResource::make()
                ->coding('device_definition_classification_type', $data['deviceCode'])
                ->toCodeableConcept();
        }

        $result = [
            'id' => $data['uuid'] ?? Str::uuid()->toString(),
            'status' => $data['status'] ?? Status::COMPLETED->value,
            'primarySource' => true,
            'performer' => FhirResource::make()
                ->coding('eHealth/resources', 'employee')
                ->toIdentifier($data['performerId']),
            'location' => FhirResource::make()
                ->coding('eHealth/resources', 'division')
                ->toIdentifier($data['locationId']),
            'whenHandedOver' => convertToEHealthISO8601($data['whenHandedOverDate'] . ' ' . $data['whenHandedOverTime']),
            'details' => [$details],
            'encounter' => FhirResource::make()
                ->coding('eHealth/resources', 'encounter')
                ->toIdentifier($uuids['encounter'])
        ];

        if (!empty($data['basedOnId'])) {
            $result['basedOn'] = FhirResource::make()
                ->coding('eHealth/resources', 'device_request')
                ->toIdentifier($data['basedOnId']);
        }

        if (!empty($data['partOfId'])) {
            $result['partOf'] = FhirResource::make()
                ->coding('eHealth/resources', 'procedure')
                ->toIdentifier($data['partOfId']);
        }

        if (!empty($data['supportingInfo'])) {
            $result['supportingInfo'] = collect($data['supportingInfo'])
                ->map(
                    fn (array $info) => FhirResource::make()
                        ->coding('eHealth/resources', $info['type'])
                        ->toIdentifier($info['uuid'])
                )
                ->values()
                ->toArray();
        }

        if (!empty($data['note'])) {
            $result['note'] = $data['note'];
        }

        return $result;
    }

    public function fromFhir(array $data, mixed ...$context): array
    {
        $detailsMap = $context[0] ?? [];
        $detail = data_get($data, 'details.0', []);
        $whenHandedOver = data_get($data, 'whenHandedOver');

        $supportingInfo = collect(data_get($data, 'supportingInfo', []))
            ->map(function (array $item) use ($detailsMap): array {
                $uuid = data_get($item, 'identifier.value');
                $type = data_get($item, 'identifier.type.coding.0.code');
                $details = $detailsMap[$uuid] ?? [];

                return [
                    'uuid' => $uuid,
                    'type' => $type,
                    'ehealthInsertedAt' => $details['ehealthInsertedAt'] ?? null,
                    'code' => $details['codeCode'] ?? null
                ];
            })
            ->values()
            ->toArray();

        return [
            'uuid' => data_get($data, 'uuid'),
            'basedOnId' => data_get($data, 'basedOn.identifier.value', ''),
            'partOfId' => data_get($data, 'partOf.identifier.value', ''),
            'performerId' => data_get($data, 'performer.identifier.value', ''),
            'locationId' => data_get($data, 'location.identifier.value', ''),
            'whenHandedOverDate' => convertToAppDateFormat($whenHandedOver),
            'whenHandedOverTime' => $whenHandedOver ? CarbonImmutable::parse($whenHandedOver)->format('H:i') : '',
            'quantity' => (int) data_get($detail, 'quantity.value', 1),
            'deviceSelectionType' => data_get($detail, 'device.identifier.value') ? 'model' : 'type',
            'deviceCode' => data_get($detail, 'deviceCode.coding.0.code', ''),
            'deviceDefinitionId' => data_get($detail, 'device.identifier.value', ''),
            'note' => data_get($data, 'note', ''),
            'supportingInfo' => $supportingInfo,
            'status' => data_get($data, 'status', Status::COMPLETED->value),
            'originEpisodeId' => data_get($data, 'originEpisodeId', ''),
            'contextEpisodeId' => data_get($data, 'contextEpisodeId', ''),
            'legalEntityName' => data_get($data, 'performerLegalEntity.displayValue', ''),
            'performerName' => data_get($data, 'performer.displayValue', ''),
            'createdDate' => convertToAppDateFormat(data_get($data, 'ehealthInsertedAt'))
        ];
    }
}