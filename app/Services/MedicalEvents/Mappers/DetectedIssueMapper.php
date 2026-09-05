<?php

declare(strict_types=1);

namespace App\Services\MedicalEvents\Mappers;

use App\Contracts\FhirMapperContract;
use App\Services\MedicalEvents\FhirResource;
use App\Enums\DetectedIssue\Status;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

class DetectedIssueMapper implements FhirMapperContract
{
    public function toFhir(array $data, mixed ...$context): array
    {
        [$uuids] = $context;

        $result = [
            'id' => $data['uuid'] ?? Str::uuid()->toString(),

            'status' => data_get($data, 'status', Status::PRELIMINARY->value),

            'subject' => FhirResource::make()
                ->coding('eHealth/resources', 'device')
                ->toIdentifier($data['subjectId']),

            'encounter' => FhirResource::make()
                ->coding('eHealth/resources', 'encounter')
                ->toIdentifier($uuids['encounter']),

            'primarySource' => $data['primarySource'],

            'recorder' => FhirResource::make()
                ->coding('eHealth/resources', 'employee')
                ->toIdentifier($uuids['employee']),
        ];

        if ($data['primarySource']) {
            $result['author'] = FhirResource::make()
                ->coding('eHealth/resources', 'employee')
                ->toIdentifier($uuids['employee']);
        } else {
            $result['author'] = (object) [];
        }

        if (!empty($data['code'])) {
            $result['code'] = FhirResource::make()
                ->coding('detected_issue_codes', $data['code'])
                ->toCodeableConcept();
        }

        if (!empty($data['detail'])) {
            $result['detail'] = $data['detail'];
        }

        if (!empty($data['identifiedDate']) && !empty($data['identifiedTime'])) {
            $result['identifiedDateTime'] = convertToEHealthISO8601($data['identifiedDate'] . ' ' . $data['identifiedTime']);
        }

        if (!empty($data['implicatedId'])) {
            $result['implicated'] = FhirResource::make()
                ->coding('eHealth/resources', 'device')
                ->toIdentifier($data['implicatedId']);
        }

        if (!empty($data['basedOnId'])) {
            $result['basedOn'] = FhirResource::make()
                ->coding('eHealth/resources', 'detected_issue')
                ->toIdentifier($data['basedOnId']);
        }

        if (!$data['primarySource']) {
            $result['reportOrigin'] = FhirResource::make()
                ->coding('eHealth/report_origins', $data['reportOriginCode'])
                ->toCodeableConcept();
        }

        return $result;
    }

    public function fromFhir(array $data, mixed ...$context): array
    {
        $identifiedDateTime = data_get($data, 'identifiedDateTime');

        return [
            'uuid' => data_get($data, 'uuid', data_get($data, 'id')),
            'subjectId' => data_get($data, 'subject.identifier.value', ''),
            'status' => data_get($data, 'status', 'preliminary'),
            'identifiedDate' => $identifiedDateTime ? convertToAppDateFormat($identifiedDateTime) : '',
            'identifiedTime' => $identifiedDateTime ? CarbonImmutable::parse($identifiedDateTime)->format('H:i') : '',
            'code' => data_get($data, 'code.coding.0.code', ''),
            'detail' => data_get($data, 'detail', ''),
            'implicatedId' => data_get($data, 'implicated.identifier.value', ''),
            'basedOnId' => data_get($data, 'basedOn.identifier.value', ''),
            'primarySource' => data_get($data, 'primarySource', true),
            'reportOriginCode' => data_get($data, 'reportOrigin.coding.0.code', ''),
        ];
    }
}