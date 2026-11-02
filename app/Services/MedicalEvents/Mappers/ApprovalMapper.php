<?php

declare(strict_types=1);

namespace App\Services\MedicalEvents\Mappers;

use App\Services\MedicalEvents\FhirResource;
use App\Contracts\FhirMapperContract;
use InvalidArgumentException;

class ApprovalMapper implements FhirMapperContract
{
    public function toFhir(array $data, mixed ...$context): array
    {
        [$employeeUuid, $legalEntityUuid] = $context;
        $payload = match ($data['mode']) {
            'patient', 'person' => [
                $data['mode'] => FhirResource::make()
                    ->coding('eHealth/resources', $data['mode'])
                    ->toIdentifier($data['person_uuid']),
            ],

            'resource' => [
                'resources' => array_map(
                    static fn (string $uuid): array => FhirResource::make()
                        ->coding('eHealth/resources', $data['resource_type'])
                        ->toIdentifier($uuid),
                    $data['resource_ids']
                ),
            ],

            'child' => [
                'child_resource' => FhirResource::make()
                    ->coding('eHealth/resources', $data['child_type'])
                    ->toIdentifier($data['child_uuid']),

                'resources' => [
                    FhirResource::make()
                        ->coding('eHealth/resources', $data['context_type'])
                        ->toIdentifier($data['context_uuid']),
                ],
            ],

            'service_request' => [
                'service_request' => FhirResource::make()
                    ->coding('eHealth/resources', 'service_request')
                    ->toIdentifier($data['service_request_uuid']),
            ],

            'diagnoses_group',
            'forbidden_group',
            'service_group' => [
                $data['mode'] => FhirResource::make()
                    ->coding('eHealth/resources', $data['mode'])
                    ->toIdentifier($data['group_id']),
            ],

            'resource_types' => [
                'resource_types' => array_map(
                    static fn (string $type): array => [
                        'coding' => [
                            FhirResource::make()
                                ->coding('eHealth/resources', $type)
                                ->toCoding(),
                        ],
                    ],
                    $data['resource_types']
                ),
            ],

            default => throw new InvalidArgumentException(
                'Unsupported approval mode.'
            ),
        };

        $payload['access_level'] = $data['access_level'];

        $payload['created_by'] = FhirResource::make()
            ->coding('eHealth/resources', 'employee')
            ->toIdentifier($employeeUuid);

        $grantToLegalEntity = $data['grant_to_legal_entity'];

        $payload['granted_to'] = FhirResource::make()
            ->coding('eHealth/resources', $grantToLegalEntity ? 'legal_entity' : 'employee')
            ->toIdentifier($grantToLegalEntity ? $legalEntityUuid : $employeeUuid);

        return $payload;
    }

    public function fromFhir(array $data, mixed ...$context): array
    {
        $mode = match (true) {
            isset($data['patient']) => 'patient',
            isset($data['person']) => 'person',
            isset($data['child_resource']) => 'child',
            isset($data['service_request']) => 'service_request',
            isset($data['diagnoses_group']) => 'diagnoses_group',
            isset($data['forbidden_group']) => 'forbidden_group',
            isset($data['service_group']) => 'service_group',
            isset($data['resource_types']) => 'resource_types',
            isset($data['resources']) => 'resource',
            default => throw new InvalidArgumentException('Unsupported approval data.'),
        };

        return [
            'mode' => $mode,
            'access_level' => $data['access_level'],
            'grant_to_legal_entity' => data_get(
                $data,
                'granted_to.identifier.type.coding.0.code'
            ) === 'legal_entity',
            ...match ($mode) {
                'patient', 'person' => [
                    'person_uuid' => data_get($data, "$mode.identifier.value"),
                ],
                'resource' => [
                    'resource_type' => data_get($data, 'resources.0.identifier.type.coding.0.code'),
                    'resource_ids' => array_map(
                        static fn (array $resource) => data_get($resource, 'identifier.value'),
                        $data['resources']
                    ),
                ],
                'child' => [
                    'child_type' => data_get($data, 'child_resource.identifier.type.coding.0.code'),
                    'child_uuid' => data_get($data, 'child_resource.identifier.value'),
                    'context_type' => data_get($data, 'resources.0.identifier.type.coding.0.code'),
                    'context_uuid' => data_get($data, 'resources.0.identifier.value'),
                ],
                'service_request' => [
                    'service_request_uuid' => data_get($data, 'service_request.identifier.value'),
                ],
                'diagnoses_group', 'forbidden_group', 'service_group' => [
                    'group_id' => data_get($data, "$mode.identifier.value"),
                ],
                'resource_types' => [
                    'resource_types' => array_map(
                        static fn (array $resource) => data_get($resource, 'coding.0.code'),
                        $data['resource_types']
                    ),
                ],
            },
        ];
    }
}
