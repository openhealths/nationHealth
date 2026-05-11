<?php

declare(strict_types=1);

namespace App\Services\MedicalEvents\Mappers;

use App\Enums\Person\DiagnosticReportStatus;
use App\Services\MedicalEvents\FhirResource;
use Illuminate\Support\Str;

class DiagnosticReportMapper
{
    /**
     * Convert a flat form diagnostic report to a FHIR structure for persistence/API.
     *
     * @param  array  $diagnosticReport
     * @param  array  $uuids
     * @return array
     */
    public function toFhir(array $diagnosticReport, array $uuids): array
    {
        $data = [
            'id' => $diagnosticReport['uuid'] ?? Str::uuid()->toString(),
            'status' => DiagnosticReportStatus::FINAL->value,
            'code' => FhirResource::make()
                ->coding('eHealth/resources', 'service')
                ->toIdentifier($diagnosticReport['codeValue']),
            'category' => [
                FhirResource::make()
                    ->coding('eHealth/diagnostic_report_categories', $diagnosticReport['categoryCode'])
                    ->toCodeableConcept()
            ],
            'effectivePeriod' => [
                'start' => convertToEHealthISO8601(
                    $diagnosticReport['effectivePeriodStartDate'] . ' ' . $diagnosticReport['effectivePeriodStartTime']
                ),
                'end' => convertToEHealthISO8601(
                    $diagnosticReport['effectivePeriodEndDate'] . ' ' . $diagnosticReport['effectivePeriodEndTime']
                ),
            ],
            'issued' => convertToEHealthISO8601(
                $diagnosticReport['issuedDate'] . ' ' . $diagnosticReport['issuedTime']
            ),
            'recordedBy' => FhirResource::make()
                ->coding('eHealth/resources', 'employee')
                ->toIdentifier($uuids['employee']),
            'encounter' => FhirResource::make()
                ->coding('eHealth/resources', 'encounter')
                ->toIdentifier($uuids['encounter']),
            'primarySource' => $diagnosticReport['primarySource'],
            'managingOrganization' => FhirResource::make()
                ->coding('eHealth/resources', 'legal_entity')
                ->toIdentifier(legalEntity()->uuid)
        ];

        if (!empty($diagnosticReport['paperReferralRequesterLegalEntityEdrpou'])) {
            $data['paperReferral'] = [
                'requisition' => $diagnosticReport['paperReferralRequisition'] ?? '',
                'requesterEmployeeName' => $diagnosticReport['paperReferralRequesterEmployeeName'] ?? '',
                'requesterLegalEntityEdrpou' => $diagnosticReport['paperReferralRequesterLegalEntityEdrpou'],
                'requesterLegalEntityName' => $diagnosticReport['paperReferralRequesterLegalEntityName'],
                'serviceRequestDate' => $diagnosticReport['paperReferralServiceRequestDate'],
                'note' => $diagnosticReport['paperReferralNote'] ?? ''
            ];
        }

        if (!empty($diagnosticReport['conclusion'])) {
            $data['conclusion'] = $diagnosticReport['conclusion'];
        }

        if (!empty($diagnosticReport['conclusionCode'])) {
            $data['conclusionCode'] = FhirResource::make()
                ->coding('eHealth/ICD10_AM/condition_codes', $diagnosticReport['conclusionCode'])
                ->toCodeableConcept();
        }

        // todo: specimens

        // todo: used_references (array of equipment)

        if (!empty($diagnosticReport['divisionId'])) {
            $data['division'] = FhirResource::make()
                ->coding('eHealth/resources', 'division')
                ->toIdentifier($diagnosticReport['divisionId']);
        }

        if ($diagnosticReport['primarySource']) {
            $data['performer'] = [
                'reference' => FhirResource::make()
                    ->coding('eHealth/resources', 'employee')
                    ->toIdentifier($uuids['employee'])
            ];
        } else {
            $data['reportOrigin'] = FhirResource::make()
                ->coding('eHealth/report_origins', $diagnosticReport['reportOriginCode'])
                ->toCodeableConcept($diagnosticReport['reportOriginText'] ?? '');
        }

        if (!empty($diagnosticReport['resultsInterpreterEmployeeId'])) {
            $data['resultsInterpreter'] = [
                'reference' => FhirResource::make()
                    ->coding('eHealth/resources', 'employee')
                    ->toIdentifier($diagnosticReport['resultsInterpreterEmployeeId'])
            ];
        }

        return $data;
    }

    /**
     * Convert a FHIR diagnostic report (from DB) to a flat form structure.
     *
     * @param  array  $diagnosticReport
     * @return array
     */
    public function fromFhir(array $diagnosticReport): array
    {
        $hasPaperReferral = !empty(data_get($diagnosticReport, 'paperReferral'));
        $hasBasedOn = !empty(data_get($diagnosticReport, 'basedOn'));

        return [
            'uuid' => data_get($diagnosticReport, 'uuid'),
            'categoryCode' => data_get($diagnosticReport, 'category.0.coding.0.code', ''),
            'codeValue' => data_get($diagnosticReport, 'code.identifier.value', ''),
            'primarySource' => data_get($diagnosticReport, 'primarySource', true),
            'reportOriginCode' => data_get($diagnosticReport, 'reportOrigin.coding.0.code', ''),
            'reportOriginText' => data_get($diagnosticReport, 'reportOrigin.text', ''),
            'isReferralAvailable' => $hasPaperReferral || $hasBasedOn,
            'referralType' => match (true) {
                $hasPaperReferral => 'paper',
                $hasBasedOn => 'electronic',
                default => '',
            },
            'paperReferralRequisition' => data_get($diagnosticReport, 'paperReferral.requisition', ''),
            'paperReferralRequesterEmployeeName' => data_get(
                $diagnosticReport,
                'paperReferral.requesterEmployeeName',
                ''
            ),
            'paperReferralRequesterLegalEntityEdrpou' => data_get(
                $diagnosticReport,
                'paperReferral.requesterLegalEntityEdrpou',
                ''
            ),
            'paperReferralRequesterLegalEntityName' => data_get(
                $diagnosticReport,
                'paperReferral.requesterLegalEntityName',
                ''
            ),
            'paperReferralServiceRequestDate' => data_get($diagnosticReport, 'paperReferral.serviceRequestDate', ''),
            'paperReferralNote' => data_get($diagnosticReport, 'paperReferral.note', ''),
            'conclusionCode' => data_get($diagnosticReport, 'conclusionCode.coding.0.code', ''),
            'conclusion' => data_get($diagnosticReport, 'conclusion', ''),
            'divisionId' => data_get($diagnosticReport, 'division.identifier.value', ''),
            'resultsInterpreterEmployeeId' => data_get(
                $diagnosticReport,
                'resultsInterpreter.reference.identifier.value',
                ''
            ),
            // The model exposes these as flat accessors (H:i:s), trimmed to H:i for the form
            'issuedDate' => data_get($diagnosticReport, 'issuedDate', ''),
            'issuedTime' => substr(data_get($diagnosticReport, 'issuedTime', ''), 0, 5),
            'effectivePeriodStartDate' => data_get($diagnosticReport, 'effectivePeriodStartDate', ''),
            'effectivePeriodStartTime' => substr(data_get($diagnosticReport, 'effectivePeriodStartTime', ''), 0, 5),
            'effectivePeriodEndDate' => data_get($diagnosticReport, 'effectivePeriodEndDate', ''),
            'effectivePeriodEndTime' => substr(data_get($diagnosticReport, 'effectivePeriodEndTime', ''), 0, 5),
            'query' => '',
        ];
    }
}
