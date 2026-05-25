<?php

declare(strict_types=1);

namespace App\Services\MedicalEvents\Mappers;

use App\Contracts\FhirMapperContract;
use App\Enums\Person\DiagnosticReportStatus;
use App\Services\MedicalEvents\FhirResource;
use Illuminate\Support\Str;

class DiagnosticReportMapper implements FhirMapperContract
{
    /**
     * Convert a flat form diagnostic report to a FHIR structure for persistence/API.
     *
     * @param  array  $data  Flat diagnostic report form data
     * @param  mixed  ...$context  [0] array $uuids  Shared UUIDs (encounter, employee, etc.)
     * @return array
     */
    public function toFhir(array $data, mixed ...$context): array
    {
        [$uuids] = $context;

        $result = [
            'id' => $data['uuid'] ?? Str::uuid()->toString(),
            'status' => DiagnosticReportStatus::FINAL->value,
            'code' => FhirResource::make()
                ->coding('eHealth/resources', 'service')
                ->toIdentifier($data['codeValue']),
            'category' => [
                FhirResource::make()
                    ->coding('eHealth/diagnostic_report_categories', $data['categoryCode'])
                    ->toCodeableConcept()
            ],
            'effectivePeriod' => [
                'start' => convertToEHealthISO8601(
                    $data['effectivePeriodStartDate'] . ' ' . $data['effectivePeriodStartTime']
                ),
                'end' => convertToEHealthISO8601(
                    $data['effectivePeriodEndDate'] . ' ' . $data['effectivePeriodEndTime']
                ),
            ],
            'issued' => convertToEHealthISO8601($data['issuedDate'] . ' ' . $data['issuedTime']),
            'recordedBy' => FhirResource::make()
                ->coding('eHealth/resources', 'employee')
                ->toIdentifier($uuids['employee']),
            'encounter' => FhirResource::make()
                ->coding('eHealth/resources', 'encounter')
                ->toIdentifier($uuids['encounter']),
            'primarySource' => $data['primarySource'],
            'managingOrganization' => FhirResource::make()
                ->coding('eHealth/resources', 'legal_entity')
                ->toIdentifier(legalEntity()->uuid)
        ];

        $paperReferral = PaperReferralMapper::toFhir($data);
        if ($paperReferral !== null) {
            $result['paperReferral'] = $paperReferral;
        }

        if (!empty($data['conclusion'])) {
            $result['conclusion'] = $data['conclusion'];
        }

        if (!empty($data['conclusionCode'])) {
            $result['conclusionCode'] = FhirResource::make()
                ->coding('eHealth/ICD10_AM/condition_codes', $data['conclusionCode'])
                ->toCodeableConcept();
        }

        // todo: specimens

        // todo: used_references (array of equipment)

        if (!empty($data['divisionId'])) {
            $result['division'] = FhirResource::make()
                ->coding('eHealth/resources', 'division')
                ->toIdentifier($data['divisionId']);
        }

        if ($data['primarySource']) {
            $result['performer'] = [
                'reference' => FhirResource::make()
                    ->coding('eHealth/resources', 'employee')
                    ->toIdentifier($uuids['employee'])
            ];
        } else {
            $result['reportOrigin'] = FhirResource::make()
                ->coding('eHealth/report_origins', $data['reportOriginCode'])
                ->toCodeableConcept($data['reportOriginText'] ?? '');
        }

        if (!empty($data['resultsInterpreterEmployeeId'])) {
            $result['resultsInterpreter'] = [
                'reference' => FhirResource::make()
                    ->coding('eHealth/resources', 'employee')
                    ->toIdentifier($data['resultsInterpreterEmployeeId'])
            ];
        }

        return $result;
    }

    /**
     * Convert a FHIR diagnostic report (from DB) to a flat form structure.
     *
     * @param  array  $data  FHIR diagnostic report data
     * @param  mixed  ...$context
     * @return array
     */
    public function fromFhir(array $data, mixed ...$context): array
    {
        return [
            'uuid' => data_get($data, 'uuid'),
            'categoryCode' => data_get($data, 'category.0.coding.0.code'),
            'codeValue' => data_get($data, 'code.identifier.value', ''),
            'primarySource' => data_get($data, 'primarySource'),
            'reportOriginCode' => data_get($data, 'reportOrigin.coding.0.code', ''),
            'reportOriginText' => data_get($data, 'reportOrigin.text', ''),
            ...PaperReferralMapper::fromFhir($data),
            'conclusionCode' => data_get($data, 'conclusionCode.coding.0.code', ''),
            'conclusion' => data_get($data, 'conclusion', ''),
            'divisionId' => data_get($data, 'division.identifier.value', ''),
            'resultsInterpreterEmployeeId' => data_get($data, 'resultsInterpreter.reference.identifier.value', ''),
            'issuedDate' => data_get($data, 'issuedDate'),
            'issuedTime' => data_get($data, 'issuedTime'),
            'effectivePeriodStartDate' => data_get($data, 'effectivePeriodStartDate', ''),
            'effectivePeriodStartTime' => data_get($data, 'effectivePeriodStartTime', ''),
            'effectivePeriodEndDate' => data_get($data, 'effectivePeriodEndDate', ''),
            'effectivePeriodEndTime' => data_get($data, 'effectivePeriodEndTime', '')
        ];
    }
}
