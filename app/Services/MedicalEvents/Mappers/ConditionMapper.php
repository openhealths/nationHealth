<?php

declare(strict_types=1);

namespace App\Services\MedicalEvents\Mappers;

use App\Contracts\FhirMapperContract;
use App\Services\MedicalEvents\FhirResource;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

class ConditionMapper implements FhirMapperContract
{
    /**
     * Convert a flat form condition to a FHIR structure for persistence/API.
     *
     * @param  array  $data  Flat condition form data
     * @param  mixed  ...$context  [0] array $uuids  Shared UUIDs (encounter, employee, etc.)
     * @return array
     */
    public function toFhir(array $data, mixed ...$context): array
    {
        [$uuids] = $context;

        $result = [
            'id' => $data['uuid'] ?? Str::uuid()->toString(),
            'primarySource' => $data['primarySource'],
            'context' => FhirResource::make()->coding('eHealth/resources', 'encounter')
                ->toIdentifier($uuids['encounter']),
            'code' => FhirResource::make()->coding($data['codeSystem'], $data['codeCode'])->toCodeableConcept(),
            'clinicalStatus' => $data['clinicalStatus'],
            'verificationStatus' => $data['verificationStatus'],
            'onsetDate' => convertToEHealthISO8601($data['onsetDate'] . ' ' . $data['onsetTime']),
        ];

        if ($data['primarySource']) {
            $result['asserter'] = [
                FhirResource::make()
                    ->coding('eHealth/resources', 'employee')
                    ->toIdentifier($uuids['employee'], $data['asserterText'] ?? '')
            ];
        } else {
            $result['reportOrigin'] = FhirResource::make()
                ->coding('eHealth/report_origins', $data['reportOriginCode'])
                ->toCodeableConcept();
        }

        if (!empty($data['severityCode'])) {
            $result['severity'] = FhirResource::make()
                ->coding('eHealth/condition_severities', $data['severityCode'])
                ->toCodeableConcept();
        }

        // todo: add  bodySites.*.code check

        if (!empty($data['assertedDate']) && !empty($data['assertedTime'])) {
            $result['assertedDate'] = convertToEHealthISO8601($data['assertedDate'] . ' ' . $data['assertedTime']);
        }

        $evidence = [];

        if (!empty($data['evidenceCodes'])) {
            $evidence['codes'] = array_map(
                static fn (array $cc) => FhirResource::make()
                    ->coding($cc['system'] ?? 'eHealth/ICPC2/reasons', $cc['code'])
                    ->toCodeableConcept($cc['text'] ?? ''),
                $data['evidenceCodes']
            );
        }

        if (!empty($data['evidenceDetails'])) {
            $evidence['details'] = array_map(
                static fn (array $detail) => FhirResource::make()
                    ->coding('eHealth/resources', $detail['type'])
                    ->toIdentifier($detail['id']),
                $data['evidenceDetails']
            );
        }

        if (!empty($evidence)) {
            $result['evidences'] = [$evidence];
        }

        return $result;
    }

    /**
     * Convert a FHIR condition (from DB) to a flat form structure.
     *
     * @param  array  $data  FHIR condition data
     * @param  mixed  ...$context  [0] array $detailsMap  UUID => [insertedAt, codeCode, type] for evidence details
     * @return array
     */
    public function fromFhir(array $data, mixed ...$context): array
    {
        $detailsMap = $context[0] ?? [];

        return [
            'uuid' => data_get($data, 'uuid'),
            'primarySource' => data_get($data, 'primarySource'),
            'codeSystem' => data_get($data, 'code.coding.0.system'),
            'codeCode' => data_get($data, 'code.coding.0.code'),
            'clinicalStatus' => data_get($data, 'clinicalStatus'),
            'verificationStatus' => data_get($data, 'verificationStatus'),
            'onsetDate' => convertToAppDateFormat(data_get($data, 'onsetDate')),
            'onsetTime' => CarbonImmutable::parse(data_get($data, 'onsetDate'))->format('H:i'),
            'assertedDate' => data_get($data, 'assertedDate')
                ? convertToAppDateFormat($data['assertedDate'])
                : null,
            'assertedTime' => data_get($data, 'assertedDate')
                ? CarbonImmutable::parse($data['assertedDate'])->format('H:i')
                : null,
            'severityCode' => data_get($data, 'severity.coding.0.code', ''),
            'asserterText' => data_get($data, 'asserter.0.identifier.type.text', data_get($data, 'asserter.identifier.type.text', '')),
            'reportOriginCode' => data_get($data, 'reportOrigin.coding.0.code', ''),
            'evidenceCodes' => array_map(
                static fn (array $code) => [
                    'code' => data_get($code, 'coding.0.code', ''),
                    'system' => data_get($code, 'coding.0.system')
                ],
                data_get($data, 'evidences.0.codes', [])
            ),
            'evidenceDetails' => array_map(
                static function (array $detail) use ($detailsMap) {
                    $uuid = data_get($detail, 'identifier.value');

                    return [
                        'id' => $uuid,
                        'ehealthInsertedAt' => $detailsMap[$uuid]['ehealthInsertedAt'] ?? '',
                        'codeCode' => $detailsMap[$uuid]['codeCode'],
                        'type' => $detailsMap[$uuid]['type']
                    ];
                },
                data_get($data, 'evidences.0.details', [])
            )
        ];
    }
}
