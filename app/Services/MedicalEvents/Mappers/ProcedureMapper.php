<?php

declare(strict_types=1);

namespace App\Services\MedicalEvents\Mappers;

use App\Contracts\FhirMapperContract;
use App\Enums\Person\ProcedureStatus;
use App\Services\MedicalEvents\FhirResource;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

class ProcedureMapper implements FhirMapperContract
{
    /**
     * Convert a flat form procedure to a FHIR structure for persistence/API.
     *
     * @param  array  $data  Flat procedure form data
     * @param  mixed  ...$context  [0] array $uuids  Shared UUIDs (encounter, employee, etc.)
     * @return array
     */
    public function toFhir(array $data, mixed ...$context): array
    {
        [$uuids] = $context;

        $result = [
            'id' => $data['uuid'] ?? Str::uuid()->toString(),
            'status' => ProcedureStatus::COMPLETED->value,
            'code' => FhirResource::make()
                ->coding('eHealth/resources', 'service')
                ->toIdentifier($data['codeValue']),
            'encounter' => FhirResource::make()
                ->coding('eHealth/resources', 'encounter')
                ->toIdentifier($uuids['encounter']),
            'performedPeriod' => [
                'start' => convertToEHealthISO8601(
                    $data['performedPeriodStartDate'] . ' ' . $data['performedPeriodStartTime']
                ),
                'end' => convertToEHealthISO8601(
                    $data['performedPeriodEndDate'] . ' ' . $data['performedPeriodEndTime']
                )
            ],
            'recordedBy' => FhirResource::make()
                ->coding('eHealth/resources', 'employee')
                ->toIdentifier($uuids['employee']),
            'primarySource' => $data['primarySource'],
            'managingOrganization' => FhirResource::make()
                ->coding('eHealth/resources', 'legal_entity')
                ->toIdentifier(legalEntity()->uuid),
            'category' => FhirResource::make()
                ->coding('eHealth/procedure_categories', $data['categoryCode'])
                ->toCodeableConcept()
        ];

        // todo: based_on

        if ($data['primarySource']) {
            $result['performer'] = FhirResource::make()
                ->coding('eHealth/resources', 'employee')
                ->toIdentifier($uuids['employee']);
        } else {
            $result['reportOrigin'] = FhirResource::make()
                ->coding('eHealth/report_origins', $data['reportOriginCode'])
                ->toCodeableConcept($data['reportOriginText']);
        }

        if (!empty($data['divisionId'])) {
            $result['division'] = FhirResource::make()
                ->coding('eHealth/resources', 'division')
                ->toIdentifier($data['divisionId']);
        }

        if (!empty($data['reasonReferences'])) {
            $result['reasonReferences'] = array_values(array_map(
                static fn (array $reasonReference) => FhirResource::make()
                    ->coding('eHealth/resources', $reasonReference['type'])
                    ->toIdentifier($reasonReference['id']),
                $data['reasonReferences']
            ));
        }

        if (!empty($data['outcomeCode'])) {
            $result['outcome'] = FhirResource::make()
                ->coding('eHealth/procedure_outcomes', $data['outcomeCode'])
                ->toCodeableConcept();
        }

        if (!empty($data['complicationDetails'])) {
            $result['complicationDetails'] = array_values(array_map(
                static fn (array $detail) => FhirResource::make()
                    ->coding('eHealth/resources', 'condition')
                    ->toIdentifier($detail['id']),
                $data['complicationDetails']
            ));
        }

        if (!empty($data['note'])) {
            $result['note'] = $data['note'];
        }

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

        if (!empty($data['usedCodes'])) {
            $result['usedCodes'] = array_values(array_map(
                static fn (array $usedCode) => FhirResource::make()
                    ->coding('eHealth/assistive_products', $usedCode['code'])
                    ->toCodeableConcept(),
                $data['usedCodes']
            ));
        }

        // todo: used_references

        // todo: focal_device

        return $result;
    }

    /**
     * Convert a FHIR procedure (from DB) to a flat form structure.
     *
     * @param  array  $data  FHIR procedure data
     * @param  mixed  ...$context  [0] array $detailsMap  UUID => [insertedAt, codeCode, type]
     * @return array
     */
    public function fromFhir(array $data, mixed ...$context): array
    {
        $detailsMap = $context[0] ?? [];
        $hasPaperReferral = !empty(data_get($data, 'paperReferral'));
        $hasBasedOn = !empty(data_get($data, 'basedOn'));

        return [
            'uuid' => data_get($data, 'uuid'),
            'categoryCode' => data_get($data, 'category.coding.0.code', ''),
            'codeValue' => data_get($data, 'code.identifier.value', ''),
            'primarySource' => data_get($data, 'primarySource'),
            'reportOriginCode' => data_get($data, 'reportOrigin.coding.0.code', ''),
            'reportOriginText' => data_get($data, 'reportOrigin.text', ''),
            'divisionId' => data_get($data, 'division.identifier.value', ''),
            'outcomeCode' => data_get($data, 'outcome.coding.0.code', ''),
            'note' => data_get($data, 'note', ''),
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
            'performedPeriodStartDate' => convertToAppDateFormat(data_get($data, 'performedPeriodStartDate')),
            'performedPeriodStartTime' => data_get($data, 'performedPeriodStartTime')
                ? CarbonImmutable::parse(data_get($data, 'performedPeriodStartTime'))->format('H:i')
                : '',
            'performedPeriodEndDate' => convertToAppDateFormat(data_get($data, 'performedPeriodEndDate')),
            'performedPeriodEndTime' => data_get($data, 'performedPeriodEndTime')
                ? CarbonImmutable::parse(data_get($data, 'performedPeriodEndTime'))->format('H:i')
                : '',
            'reasonReferences' => array_map(
                static function (array $reasonReference) use ($detailsMap) {
                    $uuid = data_get($reasonReference, 'identifier.value');
                    $details = $detailsMap[$uuid] ?? [];

                    return [
                        'id' => $uuid,
                        'type' => data_get($reasonReference, 'identifier.type.coding.0.code'),
                        'ehealthInsertedAt' => $details['ehealthInsertedAt'] ?? null,
                        'codeCode' => $details['codeCode'],
                        'codeSystem' => $details['codeSystem'] ?? null
                    ];
                },
                data_get($data, 'reasonReferences', [])
            ),
            'usedCodes' => array_map(
                static fn (array $usedCode) => ['code' => data_get($usedCode, 'coding.0.code', '')],
                data_get($data, 'usedCodes', [])
            ),
            'complicationDetails' => array_map(
                static function (array $complicationDetail) use ($detailsMap) {
                    $uuid = data_get($complicationDetail, 'identifier.value');
                    $details = $detailsMap[$uuid] ?? [];

                    return [
                        'id' => $uuid,
                        'ehealthInsertedAt' => $details['ehealthInsertedAt'] ?? null,
                        'codeCode' => $details['codeCode'],
                        'codeSystem' => $details['codeSystem']
                    ];
                },
                data_get($data, 'complicationDetails', [])
            ),
        ];
    }
}
