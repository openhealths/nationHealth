<?php

declare(strict_types=1);

namespace App\Services\MedicalEvents\Mappers;

use App\Contracts\FhirMapperContract;
use App\Core\Arr;
use App\Services\MedicalEvents\FhirResource;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

class DeviceRequestMapper implements FhirMapperContract
{
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
     * Build payload for PreQualify Device Request API.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, string|null>  $uuids
     * @return array{device_request: array<string, mixed>, programs?: list<array<string, mixed>>}
     */
    public function toPrequalifyPayload(array $data, array $uuids, string $carePlanUuid, string $activityUuid): array
    {
        return $this->wrapDeviceRequestPayload(
            $this->buildDeviceRequestBody($data, $uuids, $carePlanUuid, $activityUuid),
            $data['program_id'] ?? null
        );
    }

    /**
     * Build payload for Create Device Request API (signed PKCS#7 content).
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, string|null>  $uuids
     * @return array{device_request: array<string, mixed>, programs?: list<array<string, mixed>>}
     */
    public function toCreateSignedPayload(array $data, array $uuids, string $carePlanUuid, string $activityUuid): array
    {
        $deviceRequest = $this->buildDeviceRequestBody($data, $uuids, $carePlanUuid, $activityUuid);
        $deviceRequest['id'] = $data['uuid'];
        $deviceRequest['status'] = 'active';

        return $this->wrapDeviceRequestPayload($deviceRequest, $data['program_id'] ?? null);
    }

    /**
     * Flat payload for PKCS#7 signing on Create Device Request.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, string|null>  $uuids
     * @return array<string, mixed>
     */
    public function toCreateSignedContent(array $data, array $uuids, string $carePlanUuid, string $activityUuid): array
    {
        $wrapped = $this->toCreateSignedPayload($data, $uuids, $carePlanUuid, $activityUuid);
        $content = $wrapped['device_request'];
        unset($content['authored_on']);
        if (!empty($wrapped['programs'])) {
            $content['programs'] = $wrapped['programs'];
        }

        return $content;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, string|null>  $uuids
     * @return array<string, mixed>
     */
    private function buildDeviceRequestBody(array $data, array $uuids, string $carePlanUuid, string $activityUuid): array
    {
        $occurrence = $this->mapOccurrence($data);

        $deviceRequest = [
            'intent' => $data['intent'] ?? 'order',
            'priority' => $data['priority'] ?? 'routine',
            'quantity' => $this->mapDeviceQuantity($data),
            'encounter' => !empty($uuids['encounter_uuid'])
                ? $this->resourceIdentifier('encounter', (string) $uuids['encounter_uuid'])
                : null,
            'basedOn' => [
                $this->resourceIdentifier('care_plan', $carePlanUuid),
                $this->resourceIdentifier('activity', $activityUuid),
            ],
            'requester' => $this->resourceIdentifier('employee', (string) $uuids['employee_uuid']),
            'authoredOn' => $this->resolveAuthoredOn($occurrence['occurrencePeriod']),
        ];

        // eHealth requires exactly one of `code` (CodeableConcept) or `code_reference`
        // (Reference to device_definitions) — they are siblings, not nested under `code`.
        $deviceRequest = array_merge($deviceRequest, $this->mapDeviceCode($data));
        $deviceRequest = array_merge($deviceRequest, $occurrence);

        if (!empty($data['supporting_info'])) {
            $deviceRequest['reason'] = [];
            foreach ($data['supporting_info'] as $ref) {
                if (!empty($ref['uuid']) && !empty($ref['type'])) {
                    $deviceRequest['reason'][] = $this->resourceIdentifier(
                        strtolower((string) $ref['type']),
                        (string) $ref['uuid']
                    );
                }
            }
        }

        return array_filter($deviceRequest, static fn ($value) => $value !== null);
    }

    /**
     * @return array{device_request: array<string, mixed>, programs?: list<array<string, mixed>>}
     */
    private function wrapDeviceRequestPayload(array $deviceRequest, ?string $programId): array
    {
        $payload = [
            'device_request' => Arr::toSnakeCase($deviceRequest),
        ];

        if (!empty($programId)) {
            $payload['programs'] = [
                $this->resourceIdentifier('medical_program', $programId),
            ];
        }

        return $payload;
    }

    /**
     * @param  array{start: string, end: string}  $occurrencePeriod
     */
    private function resolveAuthoredOn(array $occurrencePeriod): string
    {
        $periodStart = CarbonImmutable::parse($occurrencePeriod['start'])->utc();
        $now = CarbonImmutable::now('UTC');
        $minStart = $now->addHour();

        if ($periodStart->greaterThanOrEqualTo($minStart)) {
            return $periodStart->format('Y-m-d\TH:i:s.000\Z');
        }

        if ($now->greaterThanOrEqualTo($minStart)) {
            return $now->format('Y-m-d\TH:i:s.000\Z');
        }

        return $minStart->format('Y-m-d\TH:i:s.000\Z');
    }

    /**
     * Build the `code` or `code_reference` fragment for a device request.
     *
     * eHealth validates that one and only one of `code` (CodeableConcept) and
     * `code_reference` (Reference to device_definitions) is present at the top
     * level of the device_request — `code` never contains an `identifier`.
     *
     * @param  array<string, mixed>  $data
     * @return array{code: array<string, mixed>}|array{code_reference: array<string, mixed>}
     */
    private function mapDeviceCode(array $data): array
    {
        $deviceId = (string) ($data['device_id'] ?? '');
        $codeType = $data['device_code_type'] ?? null;

        if ($codeType === 'DEVICE_DEFINITION' || ($codeType !== 'CLASSIFICATION_TYPE' && $this->isDeviceDefinitionUuid($deviceId))) {
            return [
                'code_reference' => $this->resourceIdentifier('device_definition', $deviceId),
            ];
        }

        return [
            'code' => [
                'coding' => [[
                    'system' => 'device_definition_classification_type',
                    'code' => $deviceId,
                ]],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{value: int, system: string, code: string}
     */
    private function mapDeviceQuantity(array $data): array
    {
        return [
            'value' => (int) ($data['quantity'] ?? 1),
            'system' => 'device_unit',
            'code' => strtolower((string) ($data['quantity_code'] ?? 'piece')),
        ];
    }

    private function isDeviceDefinitionUuid(string $value): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-/i', $value) === 1;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{occurrencePeriod: array{start: string, end: string}}
     */
    private function mapOccurrence(array $data): array
    {
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
            'occurrencePeriod' => [
                'start' => $start->utc()->format('Y-m-d\TH:i:s.000\Z'),
                'end' => $end->endOfDay()->utc()->format('Y-m-d\TH:i:s.000\Z'),
            ],
        ];
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
            'device_id' => data_get($data, 'code_reference.identifier.value')
                ?? data_get($data, 'code.coding.0.code')
                ?? data_get($data, 'codeCodeableConcept.coding.0.code'),
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
