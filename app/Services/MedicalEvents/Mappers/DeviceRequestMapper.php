<?php

declare(strict_types=1);

namespace App\Services\MedicalEvents\Mappers;

use App\Contracts\FhirMapperContract;
use App\Services\MedicalEvents\FhirResource;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

class DeviceRequestMapper implements FhirMapperContract
{
    /**
     * Convert database/form data to FHIR structure for eHealth API.
     *
     * @param  array  $data
     * @param  mixed  ...$context [0] array $uuids (person_uuid, encounter_uuid, employee_uuid, legal_entity_uuid)
     * @return array
     */
    public function toFhir(array $data, mixed ...$context): array
    {
        [$uuids] = $context;

        $result = [
            'id' => $data['uuid'] ?? Str::uuid()->toString(),
            'status' => $data['status'] ?? 'draft',
            'intent' => $data['intent'] ?? 'order',
            'codeCodeableConcept' => FhirResource::make()
                ->coding('eHealth/device_definitions', $data['device_id'])
                ->toCodeableConcept(),
            'subject' => FhirResource::make()
                ->coding('eHealth/resources', 'patient')
                ->toIdentifier($uuids['person_uuid']),
            'requester' => [
                'agent' => FhirResource::make()
                    ->coding('eHealth/resources', 'employee')
                    ->toIdentifier($uuids['employee_uuid']),
                'onBehalfOf' => FhirResource::make()
                    ->coding('eHealth/resources', 'legal_entity')
                    ->toIdentifier($uuids['legal_entity_uuid'])
            ]
        ];

        if (!empty($uuids['encounter_uuid'])) {
            $result['context'] = FhirResource::make()
                ->coding('eHealth/resources', 'encounter')
                ->toIdentifier($uuids['encounter_uuid']);
        }

        if (!empty($data['based_on_uuid'])) {
            $result['basedOn'] = [
                FhirResource::make()
                    ->coding('eHealth/resources', 'care_plan_activity')
                    ->toIdentifier($data['based_on_uuid'])
            ];
        }

        if (!empty($data['priority'])) {
            $result['priority'] = $data['priority'];
        }

        if (!empty($data['note'])) {
            $result['note'] = [['text' => $data['note']]];
        }

        if (!empty($data['program_id'])) {
            $result['program'] = FhirResource::make()
                ->coding('eHealth/medical_programs', $data['program_id'])
                ->toIdentifier($data['program_id']);
        }

        if (isset($data['quantity'])) {
            $result['quantityInteger'] = (int) $data['quantity'];
        }

        if (!empty($data['started_at']) || !empty($data['ended_at'])) {
            $result['occurrencePeriod'] = [
                'start' => $data['started_at'] ? CarbonImmutable::parse($data['started_at'])->toIso8601String() : null,
                'end' => $data['ended_at'] ? CarbonImmutable::parse($data['ended_at'])->toIso8601String() : null,
            ];
            $result['occurrencePeriod'] = array_filter($result['occurrencePeriod']);
        }

        if (!empty($data['supporting_info'])) {
            $result['supportingInfo'] = [];
            foreach ($data['supporting_info'] as $ref) {
                if (!empty($ref['uuid']) && !empty($ref['type'])) {
                    $result['supportingInfo'][] = FhirResource::make()
                        ->coding('eHealth/resources', strtolower($ref['type']))
                        ->toIdentifier($ref['uuid']);
                }
            }
        }

        return $result;
    }

    /**
     * Convert FHIR structure (from eHealth) to flat application format.
     */
    public function fromFhir(array $data, mixed ...$context): array
    {
        $supportingInfo = [];
        if (!empty($data['supportingInfo'])) {
            foreach ($data['supportingInfo'] as $si) {
                $supportingInfo[] = [
                    'uuid' => data_get($si, 'identifier.value'),
                    'type' => data_get($si, 'identifier.type.coding.0.code'),
                ];
            }
        }

        return [
            'uuid' => data_get($data, 'uuid') ?? data_get($data, 'id'),
            'status' => data_get($data, 'status'),
            'request_number' => data_get($data, 'requestNumber') ?? data_get($data, 'request_number'),
            'started_at' => data_get($data, 'occurrencePeriod.start') ?? data_get($data, 'started_at'),
            'ended_at' => data_get($data, 'occurrencePeriod.end') ?? data_get($data, 'ended_at'),
            'device_id' => data_get($data, 'codeCodeableConcept.coding.0.code'),
            'quantity' => data_get($data, 'quantityInteger'),
            'program_id' => data_get($data, 'program.identifier.value'),
            'intent' => data_get($data, 'intent'),
            'category' => data_get($data, 'category.0.coding.0.code'),
            'based_on_uuid' => data_get($data, 'basedOn.0.identifier.value'),
            'context_uuid' => data_get($data, 'context.identifier.value'),
            'priority' => data_get($data, 'priority'),
            'note' => data_get($data, 'note.0.text'),
            'supporting_info' => $supportingInfo
        ];
    }
}
