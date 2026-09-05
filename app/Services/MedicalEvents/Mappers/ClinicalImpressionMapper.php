<?php

declare(strict_types=1);

namespace App\Services\MedicalEvents\Mappers;

use App\Contracts\FhirMapperContract;
use App\Enums\Person\ClinicalImpressionStatus;
use App\Services\MedicalEvents\FhirResource;
use Illuminate\Support\Str;

class ClinicalImpressionMapper implements FhirMapperContract
{
    /**
     * Convert a flat form clinical impression to a FHIR structure for persistence/API.
     *
     * @param  array  $data  Flat clinical impression form data
     * @param  mixed  ...$context  [0] array $uuids  Shared UUIDs (encounter, employee)
     * @return array
     */
    public function toFhir(array $data, mixed ...$context): array
    {
        [$uuids] = $context;

        $result = [
            'id' => $data['uuid'] ?? Str::uuid()->toString(),
            'status' => $data['status'] ?? ClinicalImpressionStatus::COMPLETED->value,
            'code' => FhirResource::make()
                ->coding('eHealth/clinical_impression_patient_categories', $data['codeCode'])
                ->toCodeableConcept(),
            'encounter' => FhirResource::make()
                ->coding('eHealth/resources', 'encounter')
                ->toIdentifier($uuids['encounter']),
            'effectivePeriod' => [
                'start' => convertToEHealthISO8601(
                    $data['effectivePeriodStartDate'] . ' ' . $data['effectivePeriodStartTime']
                ),
                'end' => convertToEHealthISO8601(
                    $data['effectivePeriodEndDate'] . ' ' . $data['effectivePeriodEndTime']
                ),
            ],
            'assessor' => FhirResource::make()
                ->coding('eHealth/resources', 'employee')
                ->toIdentifier($uuids['employee'])
        ];

        if (!empty($data['description'])) {
            $result['description'] = $data['description'];
        }

        if (!empty($data['previous'])) {
            $result['previous'] = FhirResource::make()
                ->coding('eHealth/resources', 'clinical_impression')
                ->toIdentifier($data['previous'][0]['id']);
        }

        if (!empty($data['problems'])) {
            $result['problems'] = collect($data['problems'])
                ->map(
                    fn (array $problem) => FhirResource::make()
                        ->coding('eHealth/resources', 'condition')
                        ->toIdentifier($problem['id'])
                )
                ->values()
                ->toArray();
        }

        // todo: add summary

        if (!empty($data['findings'])) {
            $result['findings'] = collect($data['findings'])
                ->map(fn (array $finding) => [
                    'itemReference' => FhirResource::make()
                        ->coding('eHealth/resources', $finding['type'])
                        ->toIdentifier($finding['id']),
                ])
                ->values()
                ->toArray();
        }

        $supportingInfo = $this->buildSupportingInfo($data);

        if (!empty($supportingInfo)) {
            $result['supportingInfo'] = $supportingInfo;
        }

        if (!empty($data['note'])) {
            $result['note'] = $data['note'];
        }

        return $result;
    }

    /**
     * Convert a FHIR clinical impression (from DB) to a flat form structure.
     *
     * @param  array  $data  FHIR clinical impression data
     * @param  mixed  ...$context  [0] array $detailsMap  UUID => [insertedAt, codeCode, type]
     * @return array
     */
    public function fromFhir(array $data, mixed ...$context): array
    {
        $detailsMap = $context[0] ?? [];

        $previousId = data_get($data, 'previous.identifier.value');

        $allSupportingInfo = data_get($data, 'supportingInfo', []);

        $supportingInfo = collect($allSupportingInfo)
            ->map(function (array $item) use ($detailsMap) {
                $uuid = data_get($item, 'identifier.value');
                $type = data_get($item, 'identifier.type.coding.0.code');
                $details = $detailsMap[$uuid] ?? [];

                return [
                    'uuid' => $uuid,
                    'type' => $type,
                    'ehealthInsertedAt' => $details['ehealthInsertedAt'] ?? null,
                    'code' => $details['codeCode'] ?? null,
                ];
            })
            ->values()
            ->toArray();

        return [
            'uuid' => data_get($data, 'uuid'),
            'status' => data_get($data, 'status', ClinicalImpressionStatus::COMPLETED->value),
            'codeCode' => data_get($data, 'code.coding.0.code'),
            'description' => data_get($data, 'description', ''),
            'effectivePeriodStartDate' => data_get($data, 'effectivePeriodStartDate', ''),
            'effectivePeriodStartTime' => data_get($data, 'effectivePeriodStartTime', ''),
            'effectivePeriodEndDate' => data_get($data, 'effectivePeriodEndDate', ''),
            'effectivePeriodEndTime' => data_get($data, 'effectivePeriodEndTime', ''),
            'note' => data_get($data, 'note', ''),
            'previous' => $previousId ? [
                [
                    'id' => $previousId,
                    'ehealthInsertedAt' => $detailsMap[$previousId]['ehealthInsertedAt'] ?? null,
                    'codeCode' => $detailsMap[$previousId]['codeCode'] ?? null
                ],
            ] : [],
            'problems' => collect(data_get($data, 'problems', []))
                ->map(function (array $problem) use ($detailsMap) {
                    $uuid = data_get($problem, 'identifier.value');
                    $details = $detailsMap[$uuid] ?? [];

                    return [
                        'id' => $uuid,
                        'ehealthInsertedAt' => $details['ehealthInsertedAt'] ?? null,
                        'codeCode' => $details['codeCode'] ?? null,
                        'codeSystem' => $details['codeSystem'] ?? null
                    ];
                })
                ->toArray(),
            'findings' => collect(data_get($data, 'findings', []))
                ->map(function (array $finding) use ($detailsMap) {
                    $uuid = data_get($finding, 'itemReference.identifier.value');
                    $type = data_get($finding, 'itemReference.identifier.type.coding.0.code');
                    $details = $detailsMap[$uuid] ?? [];

                    return [
                        'id' => $uuid,
                        'type' => $type,
                        'ehealthInsertedAt' => $details['ehealthInsertedAt'] ?? null,
                        'codeCode' => $details['codeCode'] ?? null,
                        'codeSystem' => $details['codeSystem'] ?? null
                    ];
                })
                ->toArray(),
            'supportingInfo' => $supportingInfo
        ];
    }

    private function buildSupportingInfo(array $data): array
    {
        if (empty($data['supportingInfo'])) {
            return [];
        }

        return collect($data['supportingInfo'])
            ->map(
                fn (array $info) => FhirResource::make()
                    ->coding('eHealth/resources', $info['type'])
                    ->toIdentifier($info['uuid'])
            )
            ->values()
            ->toArray();
    }
}
