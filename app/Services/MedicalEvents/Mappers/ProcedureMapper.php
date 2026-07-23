<?php

declare(strict_types=1);

namespace App\Services\MedicalEvents\Mappers;

use App\Contracts\FhirMapperContract;
use App\Enums\Person\ProcedureStatus;
use App\Services\MedicalEvents\FhirResource;
use App\Core\Arr;
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

        $hasEncounter = !empty($uuids['encounter']);
        $status = ProcedureStatus::from($data['status']);

        $result = [
            'id' => $uuids['procedure'] ?? $data['uuid'] ?? Str::uuid()->toString(),
            'status' => $status->value,
            'code' => FhirResource::make()
                ->coding('eHealth/resources', 'service')
                ->toIdentifier($data['codeValue']),
            'recordedBy' => FhirResource::make()
                ->coding('eHealth/resources', 'employee')
                ->toIdentifier($uuids['employee']),
            'primarySource' => $data['primarySource'],
            'managingOrganization' => FhirResource::make()
                ->coding('eHealth/resources', 'legal_entity')
                ->toIdentifier(legalEntity()->uuid),
            'category' => FhirResource::make()
                ->coding('eHealth/procedure_categories', $data['categoryCode'])
                ->toCodeableConcept(),
        ];

        if ($status === ProcedureStatus::COMPLETED && ($data['performedType'] ?? null) === 'date_time') {
            $result['performedDateTime'] = convertToEHealthISO8601($data['performedDate'] . ' ' . $data['performedTime']);
        }

        if ($status === ProcedureStatus::COMPLETED && ($data['performedType'] ?? null) === 'period') {
            $result['performedPeriod'] = [
                'start' => convertToEHealthISO8601($data['performedPeriodStartDate'] . ' ' . $data['performedPeriodStartTime']),
                'end' => convertToEHealthISO8601($data['performedPeriodEndDate'] . ' ' . $data['performedPeriodEndTime']),
            ];
        }

        if (!empty($data['basedOnIdentifier'])) {
            $result['basedOn'] = FhirResource::make()
                ->coding('eHealth/resources', 'service_request')
                ->toIdentifier($data['basedOnIdentifier']);
        }

        $paperReferral = PaperReferralMapper::toFhir($data);
        if ($paperReferral !== null) {
            $result['paperReferral'] = $paperReferral;
        }

        if (!empty($data['divisionId'])) {
            $result['division'] = FhirResource::make()
                ->coding('eHealth/resources', 'division')
                ->toIdentifier($data['divisionId']);
        }

        if (!empty($data['reasonReferences'])) {
            $result['reasonReferences'] = collect($data['reasonReferences'])
                ->filter(fn (array $reasonReference) => !empty($reasonReference['id']) && !empty($reasonReference['type']))
                ->map(static fn (array $reasonReference) => FhirResource::make()
                    ->coding('eHealth/resources', $reasonReference['type'])
                    ->toIdentifier($reasonReference['id'])
                )
                ->values()
                ->toArray();
        }

        if (!empty($data['outcomeCode'])) {
            $result['outcome'] = FhirResource::make()
                ->coding('eHealth/procedure_outcomes', $data['outcomeCode'])
                ->toCodeableConcept();
        }

        if (!empty($data['note'])) {
            $result['note'] = $data['note'];
        }

        if (!empty($data['usedCodes'])) {
            $result['usedCodes'] = collect($data['usedCodes'])
                ->pluck('code')
                ->filter()
                ->unique()
                ->map(static fn (string $code) => FhirResource::make()
                    ->coding('eHealth/assistive_products', $code)
                    ->toCodeableConcept()
                )
                ->values()
                ->toArray();
        }

        if (!empty($data['usedReferences'])) {
            $result['usedReferences'] = collect($data['usedReferences'])
                ->pluck('id')
                ->filter()
                ->unique()
                ->map(static fn (string $equipmentUuid) => FhirResource::make()
                    ->coding('eHealth/resources', 'equipment')
                    ->toIdentifier($equipmentUuid)
                )
                ->values()
                ->toArray();
        }

        // todo: focal_device

        if ($data['primarySource']) {
            $result['performer'] = FhirResource::make()
                ->coding('eHealth/resources', 'employee')
                ->toIdentifier(
                    $data['performerEmployeeId']
                    ?? $uuids['procedureEmployee']
                    ?? $uuids['employee']
                );
        } else {
            $result['reportOrigin'] = FhirResource::make()
                ->coding('eHealth/report_origins', $data['reportOriginCode'])
                ->toCodeableConcept($data['reportOriginText'] ?? '');
        }

        if ($hasEncounter) {
            $result['encounter'] = FhirResource::make()
                ->coding('eHealth/resources', 'encounter')
                ->toIdentifier($uuids['encounter']);

            if (!empty($data['complicationDetails'])) {
                $result['complicationDetails'] = collect($data['complicationDetails'])
                    ->pluck('id')
                    ->filter()
                    ->unique()
                    ->map(static fn (string $conditionUuid) => FhirResource::make()
                        ->coding('eHealth/resources', 'condition')
                        ->toIdentifier($conditionUuid)
                    )
                    ->values()
                    ->toArray();
            }
        }

        return $result;
    }

    public function toCancellationPackage(
        array $procedure,
        string $statusReason,
        ?string $explanatoryLetter = null,
        ?string $statusReasonText = null
    ): array {
        $procedure = Arr::toSnakeCase($procedure);

        unset(
            $procedure['inserted_at'],
            $procedure['updated_at'],
            $procedure['created_at'],
            $procedure['updated_by'],
            $procedure['inserted_by']
        );

        $procedure['status'] = ProcedureStatus::ENTERED_IN_ERROR->value;
        $procedure['status_reason'] = FhirResource::make()
            ->coding('eHealth/procedure_status_reasons', $statusReason)
            ->toCodeableConcept($statusReasonText ?? '');

        $procedure['explanatory_letter'] = $explanatoryLetter;

        return $procedure;
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

        $performedDateTime = data_get($data, 'performedDateTime');
        $performedPeriodStartDate = data_get($data, 'performedPeriodStartDate', '');

        return [
            'uuid' => data_get($data, 'uuid'),
            'status' => data_get($data, 'status', ProcedureStatus::COMPLETED->value),
            'categoryCode' => data_get($data, 'category.coding.0.code', ''),
            'codeValue' => data_get($data, 'code.identifier.value', ''),
            'encounterId' => data_get($data, 'encounter.identifier.value', ''),
            'primarySource' => data_get($data, 'primarySource'),
            'performerEmployeeId' => data_get($data, 'performer.identifier.value', ''),
            'reportOriginCode' => data_get($data, 'reportOrigin.coding.0.code', ''),
            'reportOriginText' => data_get($data, 'reportOrigin.text', ''),
            'divisionId' => data_get($data, 'division.identifier.value', ''),
            'outcomeCode' => data_get($data, 'outcome.coding.0.code', ''),
            'note' => data_get($data, 'note', ''),
            'basedOnIdentifier' => data_get($data, 'basedOn.identifier.value', ''),
            ...PaperReferralMapper::fromFhir($data),
            'performedType' => match (true) {
                !empty($performedDateTime) => 'date_time',
                !empty($performedPeriodStartDate) => 'period',
                default => '',
            },
            'performedDate' => $performedDateTime ? convertToAppDateFormat($performedDateTime) : '',
            'performedTime' => $performedDateTime ? CarbonImmutable::parse($performedDateTime)->format('H:i') : '',
            'performedPeriodStartDate' => convertToAppDateFormat($performedPeriodStartDate),
            'performedPeriodStartTime' => data_get($data, 'performedPeriodStartTime') ? CarbonImmutable::parse(data_get($data, 'performedPeriodStartTime'))->format('H:i') : '',
            'performedPeriodEndDate' => convertToAppDateFormat(data_get($data, 'performedPeriodEndDate')),
            'performedPeriodEndTime' => data_get($data, 'performedPeriodEndTime') ? CarbonImmutable::parse(data_get($data, 'performedPeriodEndTime'))->format('H:i') : '',
            'reasonReferences' => array_map(
                static function (array $reasonReference) use ($detailsMap) {
                    $uuid = data_get($reasonReference, 'identifier.value');
                    $details = $detailsMap[$uuid] ?? [];

                    return [
                        'id' => $uuid,
                        'type' => data_get($reasonReference, 'identifier.type.coding.0.code'),
                        'ehealthInsertedAt' => $details['ehealthInsertedAt'] ?? null,
                        'codeCode' => data_get($details, 'codeCode', ''),
                        'codeSystem' => $details['codeSystem'] ?? null
                    ];
                },
                data_get($data, 'reasonReferences', [])
            ),
            'usedCodes' => array_map(
                static fn (array $usedCode) => ['code' => data_get($usedCode, 'coding.0.code', '')],
                data_get($data, 'usedCodes', [])
            ),
            'usedReferences' => array_values(array_filter(
                array_map(
                    static fn (array $usedReference) => [
                        'id' => data_get($usedReference, 'identifier.value', ''),
                    ],
                    data_get($data, 'usedReferences', [])
                ),
                static fn (array $usedReference) => !empty($usedReference['id'])
            )),
            'complicationDetails' => array_map(
                static function (array $complicationDetail) use ($detailsMap) {
                    $uuid = data_get($complicationDetail, 'identifier.value');
                    $details = $detailsMap[$uuid] ?? [];

                    return [
                        'id' => $uuid,
                        'ehealthInsertedAt' => data_get($details, 'ehealthInsertedAt'),
                        'codeCode' => data_get($details, 'codeCode', ''),
                        'codeSystem' => data_get($details, 'codeSystem'),
                    ];
                },
                data_get($data, 'complicationDetails', [])
            ),
        ];
    }
}
