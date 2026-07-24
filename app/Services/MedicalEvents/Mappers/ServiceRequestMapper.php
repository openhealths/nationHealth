<?php

declare(strict_types=1);

namespace App\Services\MedicalEvents\Mappers;

use App\Contracts\FhirMapperContract;
use App\Services\MedicalEvents\FhirResource;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

class ServiceRequestMapper implements FhirMapperContract
{
    private const CATEGORY_SYSTEM = 'eHealth/SNOMED/service_request_categories';

    /**
     * Convert database/form data to FHIR structure for eHealth API.
     *
     * @param  array  $data
     * @param  mixed  ...$context  [0] array $uuids (person_uuid, encounter_uuid, employee_uuid, legal_entity_uuid)
     * @return array
     */
    public function toFhir(array $data, mixed ...$context): array
    {
        [$uuids] = $context;

        $result = [
            'id' => $data['uuid'] ?? Str::uuid()->toString(),
            'status' => $data['status'] ?? 'draft',
            'intent' => $data['intent'] ?? 'order',
            'code' => FhirResource::make()
                ->coding('eHealth/services', $data['service_id'])
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

        if (!empty($data['category'])) {
            $result['category'] = [
                FhirResource::make()
                    ->coding(self::CATEGORY_SYSTEM, $data['category'])
                    ->toCodeableConcept()
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
     * Build payload for PreQualify Service Request API.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, string|null>  $uuids
     * @return array{service_request: array<string, mixed>}
     */
    public function toPrequalifyPayload(array $data, array $uuids, string $carePlanUuid, string $activityUuid): array
    {
        $serviceRequest = [
            'status' => 'active',
            'intent' => $data['intent'] ?? 'order',
            'priority' => $data['priority'] ?? 'routine',
            'based_on' => [
                $this->resourceIdentifier('care_plan', $carePlanUuid),
                $this->resourceIdentifier('activity', $activityUuid),
            ],
            'code' => [
                'identifier' => [
                    'type' => [
                        'coding' => [[
                            'system' => 'eHealth/resources',
                            'code' => 'service',
                        ]],
                    ],
                    'value' => $data['service_id'],
                ],
            ],
            'requester_employee' => $this->resourceIdentifier('employee', (string) $uuids['employee_uuid']),
            'requester_legal_entity' => $this->resourceIdentifier('legal_entity', (string) $uuids['legal_entity_uuid']),
        ];

        if (!empty($uuids['encounter_uuid'])) {
            $serviceRequest['context'] = $this->resourceIdentifier('encounter', (string) $uuids['encounter_uuid']);
        }

        if (!empty($data['category'])) {
            $serviceRequest['category'] = [
                'coding' => [
                    $this->mapCategoryCoding((string) $data['category']),
                ],
            ];
        }

        if (isset($data['quantity'])) {
            $serviceRequest['quantity'] = $this->mapQuantity(
                (float) $data['quantity'],
                $data['quantity_system'] ?? null,
                $data['quantity_code'] ?? null,
            );
        }

        $occurrence = $this->mapOccurrence($data);
        if ($occurrence !== null) {
            $serviceRequest = array_merge($serviceRequest, $occurrence);
        }

        $supportingInfo = $this->normalizeSupportingInfo($data['supporting_info'] ?? null);
        if (!empty($supportingInfo)) {
            $serviceRequest['supporting_info'] = $this->mapSupportingInfo($supportingInfo);
        }

        $payload = [
            'service_request' => array_filter($serviceRequest, static fn ($value) => $value !== null),
        ];

        if (!empty($data['program_id'])) {
            $payload['programs'] = [
                $this->resourceIdentifier('medical_program', (string) $data['program_id']),
            ];
        }

        return $payload;
    }

    /**
     * Build payload for Create Service Request API (signed PKCS#7 content).
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, string|null>  $uuids
     * @return array{service_request: array<string, mixed>, programs?: list<array<string, mixed>>}
     */
    public function toCreateSignedPayload(array $data, array $uuids, string $carePlanUuid, string $activityUuid): array
    {
        $payload = $this->toPrequalifyPayload($data, $uuids, $carePlanUuid, $activityUuid);
        $payload['service_request']['id'] = $data['uuid'];
        $payload['service_request']['authored_on'] = CarbonImmutable::now('UTC')->format('Y-m-d\TH:i:s.000\Z');

        return $payload;
    }

    /**
     * Flat payload for PKCS#7 signing on Create Service Request (API-007-062-0002).
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, string|null>  $uuids
     * @return array<string, mixed>
     */
    public function toCreateSignedContent(array $data, array $uuids, string $carePlanUuid, string $activityUuid): array
    {
        $wrapped = $this->toCreateSignedPayload($data, $uuids, $carePlanUuid, $activityUuid);
        $content = $wrapped['service_request'];
        unset($content['authored_on']);
        if (!empty($wrapped['programs'])) {
            $content['programs'] = $wrapped['programs'];
        }

        return $content;
    }

    /**
     * @return array{system: string, code: string}
     */
    private function mapCategoryCoding(string $category): array
    {
        return [
            'system' => self::CATEGORY_SYSTEM,
            'code' => $category,
        ];
    }

    /**
     * @return array{value: float, system: string, code: string}
     */
    private function mapQuantity(float $quantity, ?string $system = null, ?string $code = null): array
    {
        return [
            'value' => $quantity,
            'system' => $system ?: 'SERVICE_UNIT',
            'code' => $code ?: 'PIECE',
        ];
    }

    /**
     * @param  list<array{type?: string, uuid?: string}>  $supportingInfo
     * @return list<array<string, mixed>>
     */
    private function mapSupportingInfo(array $supportingInfo): array
    {
        $mapped = [];

        foreach ($supportingInfo as $ref) {
            if (!empty($ref['uuid']) && !empty($ref['type'])) {
                $mapped[] = $this->resourceIdentifier(strtolower($ref['type']), $ref['uuid']);
            }
        }

        return $mapped;
    }

    /**
     * @return list<array{type?: string, uuid?: string}>
     */
    private function normalizeSupportingInfo(mixed $supportingInfo): array
    {
        if (is_string($supportingInfo)) {
            $decoded = json_decode($supportingInfo, true);
            $supportingInfo = is_array($decoded) ? $decoded : [];
        }

        if (!is_array($supportingInfo)) {
            return [];
        }

        return array_values(array_filter($supportingInfo, static fn (mixed $item): bool => is_array($item)));
    }

    /**
     * @return array{identifier: array{type: array{coding: list<array<string, string>>}, value: string}}
     */
    private function resourceIdentifier(string $code, string $value): array
    {
        return [
            'identifier' => [
                'type' => [
                    'coding' => [[
                        'system' => 'eHealth/resources',
                        'code' => $code,
                    ]],
                ],
                'value' => $value,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{occurrence_period: array{start: string, end: string}}|null
     */
    private function mapOccurrence(array $data): ?array
    {
        if (empty($data['started_at']) && empty($data['ended_at'])) {
            return null;
        }

        $minStart = CarbonImmutable::now()->addHour();
        $start = !empty($data['started_at'])
            ? CarbonImmutable::parse($data['started_at'])
            : $minStart;

        if ($start->lessThan($minStart)) {
            $start = $minStart;
        }

        $end = !empty($data['ended_at'])
            ? CarbonImmutable::parse($data['ended_at'])
            : $start->addMonths(3);

        if ($end->lessThanOrEqualTo($start)) {
            $end = $start->addDay();
        }

        return [
            'occurrence_period' => [
                'start' => $start->toIso8601String(),
                'end' => $end->toIso8601String(),
            ],
        ];
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
            'service_id' => data_get($data, 'code.identifier.value')
                ?? data_get($data, 'code.coding.0.code'),
            'quantity' => data_get($data, 'quantity.value') ?? data_get($data, 'quantityInteger'),
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
