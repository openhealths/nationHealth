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
            'issued' => convertToEHealthISO8601(
                $data['issuedDate'] . ' ' . $data['issuedTime']
            ),
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

        if (!empty($data['paperReferralRequesterLegalEntityEdrpou'])) {
            $result['paperReferral'] = [
                'requisition' => $data['paperReferralRequisition'] ?? '',
                'requesterEmployeeName' => $data['paperReferralRequesterEmployeeName'] ?? '',
                'requesterLegalEntityEdrpou' => $data['paperReferralRequesterLegalEntityEdrpou'],
                'requesterLegalEntityName' => $data['paperReferralRequesterLegalEntityName'],
                'serviceRequestDate' => $data['paperReferralServiceRequestDate'],
                'note' => $data['paperReferralNote'] ?? ''
            ];
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
     * @return array
     */
    public function fromFhir(array $data, mixed ...$context): array
    {
        $hasPaperReferral = !empty(data_get($data, 'paperReferral'));
        $hasBasedOn = !empty(data_get($data, 'basedOn'));

        return [
            'uuid' => data_get($data, 'uuid'),
            'categoryCode' => data_get($data, 'category.0.coding.0.code', ''),
            'codeValue' => data_get($data, 'code.identifier.value', ''),
            'primarySource' => data_get($data, 'primarySource'),
            'reportOriginCode' => data_get($data, 'reportOrigin.coding.0.code', ''),
            'reportOriginText' => data_get($data, 'reportOrigin.text', ''),
            'isReferralAvailable' => $hasPaperReferral || $hasBasedOn,
            'referralType' => match (true) {
                $hasPaperReferral => 'paper',
                $hasBasedOn => 'electronic',
                default => '',
            },
            'paperReferralRequisition' => data_get($data, 'paperReferral.requisition', ''),
            'paperReferralRequesterEmployeeName' => data_get($data, 'paperReferral.requesterEmployeeName', ''),
            'paperReferralRequesterLegalEntityEdrpou' => data_get($data, 'paperReferral.requesterLegalEntityEdrpou', ''),
            'paperReferralRequesterLegalEntityName' => data_get($data, 'paperReferral.requesterLegalEntityName', ''),
            'paperReferralServiceRequestDate' => data_get($data, 'paperReferral.serviceRequestDate', ''),
            'paperReferralNote' => data_get($data, 'paperReferral.note', ''),
            'conclusionCode' => data_get($data, 'conclusionCode.coding.0.code', ''),
            'conclusion' => data_get($data, 'conclusion', ''),
            'divisionId' => data_get($data, 'division.identifier.value', ''),
            'resultsInterpreterEmployeeId' => data_get($data, 'resultsInterpreter.reference.identifier.value', ''),
            'issuedDate' => data_get($data, 'issuedDate'),
            'issuedTime' => data_get($data, 'issuedTime'),
            'effectivePeriodStartDate' => data_get($data, 'effectivePeriod.start') ? substr(data_get($data, 'effectivePeriod.start'), 0, 10) : '',
            'effectivePeriodStartTime' => data_get($data, 'effectivePeriod.start') ? substr(data_get($data, 'effectivePeriod.start'), 11, 5) : '',
            'effectivePeriodEndDate' => data_get($data, 'effectivePeriod.end') ? substr(data_get($data, 'effectivePeriod.end'), 0, 10) : '',
            'effectivePeriodEndTime' => data_get($data, 'effectivePeriod.end') ? substr(data_get($data, 'effectivePeriod.end'), 11, 5) : '',
            'query' => '',
        ];
    }
}
