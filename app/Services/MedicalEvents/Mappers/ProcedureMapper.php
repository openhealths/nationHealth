<?php

declare(strict_types=1);

namespace App\Services\MedicalEvents\Mappers;

use App\Enums\Person\ProcedureStatus;
use App\Services\MedicalEvents\FhirResource;
use Illuminate\Support\Str;

class ProcedureMapper
{
    /**
     * Convert a flat form procedure to a FHIR structure for persistence/API.
     *
     * @param  array  $procedure
     * @param  array  $uuids
     * @return array
     */
    public function toFhir(array $procedure, array $uuids): array
    {
        $data = [
            'id' => $procedure['uuid'] ?? Str::uuid()->toString(),
            'status' => ProcedureStatus::COMPLETED->value,
            'code' => FhirResource::make()
                ->coding('eHealth/resources', 'service')
                ->toIdentifier($procedure['codeValue']),
            'encounter' => FhirResource::make()
                ->coding('eHealth/resources', 'encounter')
                ->toIdentifier($uuids['encounter']),
            'performedPeriod' => [
                'start' => convertToEHealthISO8601(
                    $procedure['performedPeriodStartDate'] . ' ' . $procedure['performedPeriodStartTime']
                ),
                'end' => convertToEHealthISO8601(
                    $procedure['performedPeriodEndDate'] . ' ' . $procedure['performedPeriodEndTime']
                )
            ],
            'recordedBy' => FhirResource::make()
                ->coding('eHealth/resources', 'employee')
                ->toIdentifier($uuids['employee']),
            'primarySource' => $procedure['primarySource'],
            'managingOrganization' => FhirResource::make()
                ->coding('eHealth/resources', 'legal_entity')
                ->toIdentifier(legalEntity()->uuid),
            'category' => FhirResource::make()
                ->coding('eHealth/procedure_categories', $procedure['categoryCode'])
                ->toCodeableConcept()
        ];

        // todo: based_on

        if ($procedure['primarySource']) {
            $data['performer'] = FhirResource::make()
                ->coding('eHealth/resources', 'employee')
                ->toIdentifier($uuids['employee']);
        } else {
            $data['reportOrigin'] = FhirResource::make()
                ->coding('eHealth/report_origins', $procedure['reportOriginCode'])
                ->toCodeableConcept($procedure['reportOriginText']);
        }

        if (!empty($procedure['divisionId'])) {
            $data['division'] = FhirResource::make()
                ->coding('eHealth/resources', 'division')
                ->toIdentifier($procedure['divisionId']);
        }

        if (!empty($procedure['reasonReferences'])) {
            $data['reasonReferences'] = collect($procedure['reasonReferences'])
                ->map(
                    fn (array $reasonReference) => FhirResource::make()
                        ->coding('eHealth/resources', $reasonReference['type'])
                        ->toIdentifier($reasonReference['id'])
                )
                ->values()
                ->toArray();
        }

        if (!empty($procedure['outcomeCode'])) {
            $data['outcome'] = FhirResource::make()
                ->coding('eHealth/procedure_outcomes', $procedure['outcomeCode'])
                ->toCodeableConcept();
        }

        if (!empty($procedure['complicationDetails'])) {
            $data['complicationDetails'] = collect($procedure['complicationDetails'])
                ->map(
                    fn (array $detail) => FhirResource::make()
                        ->coding('eHealth/resources', 'condition')
                        ->toIdentifier($detail['id'])
                )
                ->values()
                ->toArray();
        }

        if (!empty($procedure['note'])) {
            $data['note'] = $procedure['note'];
        }

        if (!empty($procedure['paperReferralRequesterLegalEntityEdrpou'])) {
            $data['paperReferral'] = [
                'requisition' => $procedure['paperReferralRequisition'] ?? '',
                'requesterEmployeeName' => $procedure['paperReferralRequesterEmployeeName'] ?? '',
                'requesterLegalEntityEdrpou' => $procedure['paperReferralRequesterLegalEntityEdrpou'],
                'requesterLegalEntityName' => $procedure['paperReferralRequesterLegalEntityName'],
                'serviceRequestDate' => $procedure['paperReferralServiceRequestDate'],
                'note' => $procedure['paperReferralNote'] ?? ''
            ];
        }

        if (!empty($procedure['usedCodes'])) {
            $data['usedCodes'] = collect($procedure['usedCodes'])
                ->map(
                    fn (array $uc) => FhirResource::make()
                        ->coding('eHealth/assistive_products', $uc['code'])
                        ->toCodeableConcept()
                )
                ->values()
                ->toArray();
        }

        // todo: used_references

        // todo: focal_device

        return $data;
    }

    /**
     * Convert a FHIR procedure (from DB) to a flat form structure.
     *
     * @param  array  $procedure
     * @param  array  $detailsMap  UUID => [insertedAt, codeCode, type]
     * @return array
     */
    public function fromFhir(array $procedure, array $detailsMap = []): array
    {
        $hasPaperReferral = !empty(data_get($procedure, 'paperReferral'));
        $hasBasedOn = !empty(data_get($procedure, 'basedOn'));

        return [
            'uuid' => data_get($procedure, 'uuid'),
            'categoryCode' => data_get($procedure, 'category.coding.0.code', ''),
            'codeValue' => data_get($procedure, 'code.identifier.value', ''),
            'primarySource' => data_get($procedure, 'primarySource', true),
            'reportOriginCode' => data_get($procedure, 'reportOrigin.coding.0.code', ''),
            'reportOriginText' => data_get($procedure, 'reportOrigin.text', ''),
            'divisionId' => data_get($procedure, 'division.identifier.value', ''),
            'outcomeCode' => data_get($procedure, 'outcome.coding.0.code', ''),
            'note' => data_get($procedure, 'note', ''),
            'isReferralAvailable' => $hasPaperReferral || $hasBasedOn,
            'referralType' => match (true) {
                $hasPaperReferral => 'paper',
                $hasBasedOn => 'electronic',
                default => '',
            },
            'paperReferralRequisition' => data_get($procedure, 'paperReferral.requisition', ''),
            'paperReferralRequesterEmployeeName' => data_get($procedure, 'paperReferral.requesterEmployeeName', ''),
            'paperReferralRequesterLegalEntityEdrpou' => data_get(
                $procedure,
                'paperReferral.requesterLegalEntityEdrpou',
                ''
            ),
            'paperReferralRequesterLegalEntityName' => data_get(
                $procedure,
                'paperReferral.requesterLegalEntityName',
                ''
            ),
            'paperReferralServiceRequestDate' => data_get($procedure, 'paperReferral.serviceRequestDate', ''),
            'paperReferralNote' => data_get($procedure, 'paperReferral.note', ''),
            // Model appends these as flat accessors (H:i:s), trimmed to H:i for the form
            'performedPeriodStartDate' => data_get($procedure, 'performedPeriodStartDate', ''),
            'performedPeriodStartTime' => substr(data_get($procedure, 'performedPeriodStartTime', ''), 0, 5),
            'performedPeriodEndDate' => data_get($procedure, 'performedPeriodEndDate', ''),
            'performedPeriodEndTime' => substr(data_get($procedure, 'performedPeriodEndTime', ''), 0, 5),
            'reasonReferences' => collect(data_get($procedure, 'reasonReferences', []))
                ->map(function (array $rr) use ($detailsMap) {
                    $uuid = data_get($rr, 'identifier.value');
                    $details = $detailsMap[$uuid] ?? [];

                    return [
                        'id' => $uuid,
                        'type' => data_get($rr, 'identifier.type.coding.0.code'),
                        'insertedAt' => $details['insertedAt'] ?? null,
                        'codeCode' => $details['codeCode'] ?? null,
                    ];
                })
                ->toArray(),
            'usedCodes' => collect(data_get($procedure, 'usedCodes', []))
                ->map(fn (array $uc) => [
                    'code' => data_get($uc, 'coding.0.code', '')
                ])
                ->toArray(),
            'complicationDetails' => collect(data_get($procedure, 'complicationDetails', []))
                ->map(function (array $cd) use ($detailsMap) {
                    $uuid = data_get($cd, 'identifier.value');
                    $details = $detailsMap[$uuid] ?? [];

                    return [
                        'id' => $uuid,
                        'insertedAt' => $details['insertedAt'] ?? null,
                        'codeCode' => $details['codeCode'] ?? null,
                    ];
                })
                ->toArray(),
        ];
    }
}
