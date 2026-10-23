<?php

declare(strict_types=1);

namespace App\Services\MedicalEvents\Mappers;

use App\Contracts\FhirMapperContract;
use App\Enums\Specimen\Status;
use App\Services\MedicalEvents\FhirResource;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

class SpecimenMapper implements FhirMapperContract
{
    /**
     * Map flat specimen form data to the FHIR specimen structure.
     *
     * @param  array  $data
     * @param  mixed  ...$context
     * @return array
     */
    public function toFhir(array $data, mixed ...$context): array
    {
        [$uuids] = $context;

        $isReferenced = $data['isReferenced'] ?? false;

        $result = [
            'id' => $data['uuid'] ?? Str::uuid()->toString(),
            'status' => $isReferenced ? Status::UNAVAILABLE->value : Status::AVAILABLE->value,
            'type' => FhirResource::make()
                ->coding('specimen_types', $data['typeCode'])
                ->toCodeableConcept($data['typeText'] ?? ''),
            'managingOrganization' => FhirResource::make()
                ->coding('eHealth/resources', 'legal_entity')
                ->toIdentifier(legalEntity()->uuid),
            'registeredBy' => FhirResource::make()
                ->coding('eHealth/resources', 'employee')
                ->toIdentifier($uuids['employee']),
            'collection' => $this->collectionToFhir($data),
            'container' => collect($data['containers'])
                ->map(fn (array $container): array => $this->containerToFhir($container))
                ->values()
                ->toArray()
        ];

        // A specimen created outside the encounter package has no encounter to refer to
        if (!empty($uuids['encounter'])) {
            $result['context'] = FhirResource::make()
                ->coding('eHealth/resources', 'encounter')
                ->toIdentifier($uuids['encounter']);
        }

        if ($isReferenced) {
            $result['statusReason'] = FhirResource::make()
                ->coding('specimen_invalidate_reasons', 'used')
                ->toCodeableConcept();
        }

        if ($isReferenced && !empty($data['receivedDate']) && !empty($data['receivedTime'])) {
            $result['receivedTime'] = convertToEHealthISO8601($data['receivedDate'] . ' ' . $data['receivedTime']);
        }

        if (!empty($data['conditionCode'])) {
            $result['condition'] = FhirResource::make()
                ->coding('specimen_conditions', $data['conditionCode'])
                ->toCodeableConcept($data['conditionText'] ?? '');
        }

        // A parent select left unpicked keeps an empty value
        $parentIds = array_filter($data['parentIds'] ?? []);

        if (!empty($parentIds)) {
            $result['parent'] = collect($parentIds)
                ->map(
                    static fn (string $parentId): array => FhirResource::make()
                        ->coding('eHealth/resources', 'specimen')
                        ->toIdentifier($parentId)
                )
                ->values()
                ->toArray();
        }

        if (!empty($data['note'])) {
            $result['note'] = $data['note'];
        }

        return $result;
    }

    /**
     * Map FHIR specimen structure to flat specimen form data.
     *
     * @param  array  $data
     * @param  mixed  ...$context
     * @return array
     */
    public function fromFhir(array $data, mixed ...$context): array
    {
        $collectedDateTime = data_get($data, 'collection.collectedDateTime');
        $periodStart = data_get($data, 'collection.collectedPeriod.start');
        $periodEnd = data_get($data, 'collection.collectedPeriod.end');
        $receivedTime = data_get($data, 'receivedTime');
        $collectorType = data_get($data, 'collection.collector.identifier.type.coding.0.code');

        return [
            'uuid' => data_get($data, 'id', data_get($data, 'uuid')),
            'typeCode' => data_get($data, 'type.coding.0.code', ''),
            'typeText' => data_get($data, 'type.text') ?? '',
            'conditionCode' => data_get($data, 'condition.coding.0.code', ''),
            'conditionText' => data_get($data, 'condition.text') ?? '',
            'receivedDate' => $receivedTime ? convertToAppDateFormat($receivedTime) : '',
            'receivedTime' => $receivedTime ? CarbonImmutable::parse($receivedTime)->format('H:i') : '',
            'note' => data_get($data, 'note', ''),
            'parentIds' => collect(data_get($data, 'parent', []))
                ->map(static fn (array $parent): string => data_get($parent, 'identifier.value', ''))
                ->filter()
                ->values()
                ->toArray(),
            'registeredById' => data_get($data, 'registeredBy.identifier.value', ''),
            'collectorType' => $collectorType === 'patient' ? 'patient' : 'other',
            'collectorId' => data_get($data, 'collection.collector.identifier.value', ''),
            'collectedType' => $periodStart ? 'period' : 'date_time',
            'collectedDate' => $collectedDateTime ? convertToAppDateFormat($collectedDateTime) : '',
            'collectedTime' => $collectedDateTime ? CarbonImmutable::parse($collectedDateTime)->format('H:i') : '',
            'collectedPeriodRange' => implode(' — ', array_filter([
                $periodStart ? convertToAppDateFormat($periodStart) : '',
                $periodEnd ? convertToAppDateFormat($periodEnd) : ''
            ])),
            'collectedPeriodStartTime' => $periodStart ? CarbonImmutable::parse($periodStart)->format('H:i') : '',
            'collectedPeriodEndTime' => $periodEnd ? CarbonImmutable::parse($periodEnd)->format('H:i') : '',
            'durationValue' => data_get($data, 'collection.duration.value', ''),
            'durationCode' => data_get($data, 'collection.duration.code', ''),
            'quantityValue' => data_get($data, 'collection.quantity.value', ''),
            'quantityCode' => data_get($data, 'collection.quantity.code', ''),
            'methodCode' => data_get($data, 'collection.method.coding.0.code', ''),
            'bodySiteCode' => data_get($data, 'collection.bodySite.coding.0.code', ''),
            'fastingStatusCode' => data_get($data, 'collection.fastingStatusCodeableConcept.coding.0.code', ''),
            'procedureId' => data_get($data, 'collection.procedure.identifier.value', ''),
            'containers' => collect(data_get($data, 'container', []))
                ->map(static fn (array $container): array => [
                    'identifier' => data_get($container, 'identifier', ''),
                    'description' => data_get($container, 'description', ''),
                    'typeCode' => data_get($container, 'type.coding.0.code', ''),
                    'typeText' => data_get($container, 'type.text') ?? '',
                    'capacityValue' => data_get($container, 'capacity.value', ''),
                    'capacityCode' => data_get($container, 'capacity.code', ''),
                    'specimenQuantityValue' => data_get($container, 'specimenQuantity.value', ''),
                    'specimenQuantityCode' => data_get($container, 'specimenQuantity.code', ''),
                    'additiveCode' => data_get($container, 'additiveCodeableConcept.coding.0.code', ''),
                    'additiveText' => data_get($container, 'additiveCodeableConcept.text') ?? ''
                ])
                ->values()
                ->toArray()
        ];
    }

    /**
     * Build a FHIR structure out of the time the specimen was received for processing.
     *
     * @param  array  $data  Flat specimen process form data
     * @return array
     */
    public function toProcessFhir(array $data): array
    {
        return [
            'receivedTime' => convertToEHealthISO8601(
                $data['receivedDate'] . ' ' . $data['receivedTime']
            )
        ];
    }

    /**
     * Build a FHIR structure out of the reason the specimen is marked as rejected.
     *
     * @param  array  $data  Flat specimen reject form data
     * @return array
     */
    public function toRejectFhir(array $data): array
    {
        return [
            'statusReason' => FhirResource::make()
                ->coding('specimen_reject_reasons', $data['rejectReason'])
                ->toCodeableConcept()
        ];
    }

    /**
     * Turn the specimen as eHealth returns it into the signed content that marks it as entered in error.
     *
     * @param  array  $specimen  Specimen details as returned by eHealth
     * @param  string  $cancellationReason
     * @return array
     */
    public function toCancellationPackage(array $specimen, string $cancellationReason): array
    {
        return [
            ...$specimen,
            'status' => Status::ENTERED_IN_ERROR->value,
            'status_reason' => FhirResource::make()
                ->coding('specimen_cancel_reasons', $cancellationReason)
                ->toCodeableConcept()
        ];
    }

    /**
     * Build a FHIR structure out of the reason the specimen is marked as unavailable.
     *
     * @param  array  $data  Flat specimen invalidate form data
     * @return array
     */
    public function toInvalidateFhir(array $data): array
    {
        return [
            'statusReason' => FhirResource::make()
                ->coding('specimen_invalidate_reasons', $data['invalidateReason'])
                ->toCodeableConcept()
        ];
    }

    /**
     * Build the collection details of the specimen.
     *
     * @param  array  $data
     * @return array
     */
    private function collectionToFhir(array $data): array
    {
        $collection = [
            'collector' => FhirResource::make()
                ->coding('eHealth/resources', $data['collectorType'] === 'patient' ? 'patient' : 'employee')
                ->toIdentifier($data['collectorId'])
        ];

        if ($data['collectedType'] === 'period') {
            // The range picker keeps both bounds in one field
            $periodBounds = array_map('trim', explode('—', $data['collectedPeriodRange']));

            $collection['collectedPeriod'] = [
                'start' => convertToEHealthISO8601($periodBounds[0] . ' ' . $data['collectedPeriodStartTime'])
            ];

            if (!empty($periodBounds[1]) && !empty($data['collectedPeriodEndTime'])) {
                $collection['collectedPeriod']['end'] = convertToEHealthISO8601(
                    $periodBounds[1] . ' ' . $data['collectedPeriodEndTime']
                );
            }
        } else {
            $collection['collectedDateTime'] = convertToEHealthISO8601($data['collectedDate'] . ' ' . $data['collectedTime']);
        }

        if (!empty($data['durationValue'])) {
            $collection['duration'] = $this->quantityToFhir($data['durationValue'], $data['durationCode']);
        }

        if (!empty($data['quantityValue'])) {
            $collection['quantity'] = $this->quantityToFhir($data['quantityValue'], $data['quantityCode']);
        }

        if (!empty($data['methodCode'])) {
            $collection['method'] = FhirResource::make()
                ->coding('specimen_collection_methods', $data['methodCode'])
                ->toCodeableConcept();
        }

        if (!empty($data['bodySiteCode'])) {
            $collection['bodySite'] = FhirResource::make()
                ->coding('eHealth/body_sites', $data['bodySiteCode'])
                ->toCodeableConcept();
        }

        if (!empty($data['fastingStatusCode'])) {
            $collection['fastingStatusCodeableConcept'] = FhirResource::make()
                ->coding('fasting_statuses', $data['fastingStatusCode'])
                ->toCodeableConcept();
        }

        if (!empty($data['procedureId'])) {
            $collection['procedure'] = FhirResource::make()
                ->coding('eHealth/resources', 'procedure')
                ->toIdentifier($data['procedureId']);
        }

        return $collection;
    }

    /**
     * Build a container of the specimen.
     *
     * @param  array  $container
     * @return array
     */
    private function containerToFhir(array $container): array
    {
        $result = ['identifier' => $container['identifier']];

        if (!empty($container['description'])) {
            $result['description'] = $container['description'];
        }

        if (!empty($container['typeCode'])) {
            $result['type'] = FhirResource::make()
                ->coding('specimen_container_types', $container['typeCode'])
                ->toCodeableConcept($container['typeText'] ?? '');
        }

        if (!empty($container['capacityValue'])) {
            $result['capacity'] = $this->quantityToFhir($container['capacityValue'], $container['capacityCode']);
        }

        if (!empty($container['specimenQuantityValue'])) {
            $result['specimenQuantity'] = $this->quantityToFhir(
                $container['specimenQuantityValue'],
                $container['specimenQuantityCode']
            );
        }

        if (!empty($container['additiveCode'])) {
            $result['additiveCodeableConcept'] = FhirResource::make()
                ->coding('specimen_container_additives', $container['additiveCode'])
                ->toCodeableConcept($container['additiveText'] ?? '');
        }

        return $result;
    }

    /**
     * Build a quantity measured in UCUM units.
     *
     * @param  int|float|string  $value
     * @param  string  $code
     * @return array
     */
    private function quantityToFhir(int|float|string $value, string $code): array
    {
        return [
            'value' => (float) $value,
            'system' => 'eHealth/ucum/units',
            'code' => $code
        ];
    }
}
