<?php

declare(strict_types=1);

namespace App\Services\MedicalEvents\Mappers;

use App\Contracts\FhirMapperContract;
use App\Enums\Person\ObservationStatus;
use App\Repositories\Repository;
use App\Services\MedicalEvents\FhirResource;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

class ObservationMapper implements FhirMapperContract
{
    /**
     * Convert a flat form observation to a FHIR structure for persistence/API.
     *
     * @param  array  $data  Flat observation form data
     * @param  mixed  ...$context  [0] array $uuids  Shared UUIDs (encounter, employee, etc.)
     * @return array
     */
    public function toFhir(array $data, mixed ...$context): array
    {
        [$uuids] = $context;

        $result = [
            'id' => $data['uuid'] ?? Str::uuid()->toString(),
            'status' => $data['status'] ?? ObservationStatus::VALID->value,
            'categories' => [
                FhirResource::make()
                    ->coding($data['categorySystem'], $data['categoryCode'])
                    ->toCodeableConcept()
            ],
            'code' => FhirResource::make()
                ->coding($data['codeSystem'], $data['codeCode'])
                ->toCodeableConcept(),
            'issued' => convertToEHealthISO8601($data['issuedDate'] . ' ' . $data['issuedTime']),
            'primarySource' => $data['primarySource']
        ];

        if (!empty($uuids['encounter'])) {
            $result['context'] = FhirResource::make()
                ->coding('eHealth/resources', 'encounter')
                ->toIdentifier($uuids['encounter']);
        }

        if (!empty($uuids['diagnosticReport'])) {
            $result['diagnosticReport'] = FhirResource::make()
                ->coding('eHealth/resources', 'diagnostic_report')
                ->toIdentifier($uuids['diagnosticReport']);
        }

        $effectiveType = $data['effectiveType'] ?? 'date_time';
        // The range picker keeps both bounds in one field
        $periodBounds = array_map('trim', explode('—', $data['effectivePeriodRange'] ?? ''));

        if ($effectiveType === 'period' && !empty($periodBounds[0])) {
            // A period within one day leaves the picker with a single date
            $endDate = empty($periodBounds[1]) ? $periodBounds[0] : $periodBounds[1];

            $result['effectivePeriod'] = [
                'start' => convertToEHealthISO8601(
                    $periodBounds[0] . ' ' . $data['effectivePeriodStartTime']
                ),
                'end' => convertToEHealthISO8601(
                    $endDate . ' ' . $data['effectivePeriodEndTime']
                )
            ];
        } elseif ($effectiveType === 'date_time' && !empty($data['effectiveDate']) && !empty($data['effectiveTime'])) {
            $result['effectiveDateTime'] = convertToEHealthISO8601(
                $data['effectiveDate'] . ' ' . $data['effectiveTime']
            );
        }

        if ($data['primarySource']) {
            $result['performer'] = [
                FhirResource::make()
                    ->coding('eHealth/resources', 'employee')
                    // An observation of a diagnostic report has no performer to choose, so its writer performs it
                    ->toIdentifier(($data['performerEmployeeId'] ?? '') ?: $uuids['employee'])
            ];
        } else {
            $result['reportOrigin'] = FhirResource::make()
                ->coding('eHealth/report_origins', $data['reportOriginCode'])
                ->toCodeableConcept($data['reportOriginText'] ?? '');
        }

        if (!empty($data['interpretationCode'])) {
            $result['interpretation'] = FhirResource::make()
                ->coding('eHealth/observation_interpretations', $data['interpretationCode'])
                ->toCodeableConcept();
        }

        if (!empty($data['comment'])) {
            $result['comment'] = $data['comment'];
        }

        if (!empty($data['methodCode'])) {
            $result['method'] = FhirResource::make()
                ->coding('eHealth/observation_methods', $data['methodCode'])
                ->toCodeableConcept();
        }

        if (!empty($data['bodySiteCode'])) {
            $result['bodySite'] = FhirResource::make()
                ->coding('eHealth/body_sites', $data['bodySiteCode'])
                ->toCodeableConcept();
        }

        if (!empty($data['reactionOn'])) {
            $result['reactionOn'] = FhirResource::make()
                ->coding('eHealth/resources', 'immunization')
                ->toIdentifier($data['reactionOn']);
        }

        $result = array_merge($result, $this->buildValue($data));

        $componentScores = Repository::observationConfig()->componentMap()[$data['codeCode'] ?? '']['scores'] ?? [];

        $fhirComponents = collect($data['components'] ?? [])
            ->filter(
                static fn (array $component): bool => !empty($component['valueCode'])
                    || ($component['valueQuantityValue'] ?? '') !== ''
            )
            ->map(function (array $component) use ($componentScores): array {
                $fhirComponent = [
                    'code' => FhirResource::make()
                        ->coding($component['codeSystem'] ?? 'eHealth/ICF/qualifiers', $component['codeCode'])
                        ->toCodeableConcept()
                ];

                if (!empty($component['interpretationCode'])) {
                    $fhirComponent['interpretation'] = FhirResource::make()
                        ->coding('eHealth/observation_interpretations', $component['interpretationCode'])
                        ->toCodeableConcept();
                }

                $value = !empty($component['valueCode'])
                    ? ['valueCodeableConcept' => $component['valueCode'], 'dictionaryName' => $component['valueSystem']]
                    : [
                        'valueQuantityValue' => $component['valueQuantityValue'],
                        'valueQuantityComparator' => '=',
                        'valueQuantityUnit' => $component['valueQuantityCode'],
                        'valueQuantitySystem' => 'eHealth/ucum/units',
                        'valueQuantityCode' => $component['valueQuantityCode']
                    ];

                $fhirComponent = array_merge($fhirComponent, $this->buildValue($value));

                $score = $componentScores[$component['valueSystem'] ?? ''][$component['valueCode'] ?? ''] ?? null;

                if ($score !== null) {
                    $fhirComponent['valueCodeableConcept']['coding'][0]['extension'] = [
                        ['code' => 'item_weight', 'valueDecimal' => $score]
                    ];
                }

                return $fhirComponent;
            })
            ->values()
            ->toArray();

        if (!empty($fhirComponents)) {
            $result['components'] = $fhirComponents;
        }

        if (!empty($data['deviceId'])) {
            $result['device'] = FhirResource::make()
                ->coding('eHealth/resources', 'equipment')
                ->toIdentifier($data['deviceId']);
        }

        if (!empty($data['specimenId'])) {
            $result['specimen'] = FhirResource::make()
                ->coding('eHealth/resources', 'specimen')
                ->toIdentifier($data['specimenId']);
        }

        return $result;
    }

    /**
     * Build FHIR value fields from flat form data.
     *
     * @param  array  $data
     * @return array
     */
    private function buildValue(array $data): array
    {
        $value = [];

        if (($data['valueQuantityValue'] ?? '') !== '') {
            $value['valueQuantity'] = [
                'value' => $data['valueQuantityValue'],
                'comparator' => $data['valueQuantityComparator'],
                'unit' => $data['valueQuantityUnit'],
                'system' => $data['valueQuantitySystem'],
                'code' => $data['valueQuantityCode']
            ];
        }

        if (isset($data['valueCodeableConcept'])) {
            $value['valueCodeableConcept'] = FhirResource::make()
                ->coding($data['dictionaryName'], $data['valueCodeableConcept'])
                ->toCodeableConcept();
        }

        if (isset($data['valueSampledData'])) {
            $value['valueSampledData'] = [
                'origin' => $data['valueSampledDataOrigin'],
                'period' => $data['valueSampledDataPeriod'],
                'factor' => $data['valueSampledDataFactor'],
                'lowerLimit' => $data['valueSampledDataLowerLimit'],
                'upperLimit' => $data['valueSampledDataUpperLimit'],
                'dimensions' => $data['valueSampledDataDimensions'],
                'data' => $data['valueSampledDataData']
            ];
        }

        if (isset($data['valueString'])) {
            $value['valueString'] = $data['valueString'];
        }

        if (isset($data['valueBoolean'])) {
            $value['valueBoolean'] = $data['valueBoolean'];
        }

        if (isset($data['valueDate'], $data['valueTime'])) {
            $value['valueDateTime'] = convertToEHealthISO8601(
                $data['valueDate'] . ' ' . $data['valueTime']
            );
        } elseif (isset($data['valueTime'])) {
            $value['valueTime'] = $data['valueTime'] . ':00';
        }

        return $value;
    }

    /**
     * Convert a FHIR observation (from DB) to a flat form structure.
     *
     * @param  array  $data  FHIR observation data
     * @param  mixed  ...$context
     * @return array
     */
    public function fromFhir(array $data, mixed ...$context): array
    {
        $categorySystem = data_get($data, 'categories.0.coding.0.system');
        $codeSystem = data_get($data, 'code.coding.0.system');

        if (str_contains($categorySystem, 'ICF')) {
            $codingSystem = 'icf';
        } elseif ($codeSystem === 'eHealth/custom/observation_codes') {
            $codingSystem = 'custom';
        } else {
            $codingSystem = 'loinc';
        }

        $flat = [
            'uuid' => data_get($data, 'uuid'),
            'status' => data_get($data, 'status', ObservationStatus::VALID->value),
            'codingSystem' => $codingSystem,
            'categorySystem' => $categorySystem,
            'codeSystem' => data_get($data, 'code.coding.0.system'),
            'primarySource' => data_get($data, 'primarySource'),
            'performerEmployeeId' => data_get($data, 'performer.0.identifier.value', data_get($data, 'performer.identifier.value', '')),
            'reportOriginCode' => data_get($data, 'reportOrigin.coding.0.code', ''),
            'categoryCode' => data_get($data, 'categories.0.coding.0.code'),
            'codeCode' => data_get($data, 'code.coding.0.code'),
            'methodCode' => data_get($data, 'method.coding.0.code', ''),
            'interpretationCode' => data_get($data, 'interpretation.coding.0.code', ''),
            'bodySiteCode' => data_get($data, 'bodySite.coding.0.code', ''),
            'valueQuantityValue' => data_get($data, 'value.valueQuantity.value', ''),
            'valueQuantityComparator' => data_get($data, 'value.valueQuantity.comparator', ''),
            'valueQuantityUnit' => data_get($data, 'value.valueQuantity.unit', ''),
            'valueQuantitySystem' => data_get($data, 'value.valueQuantity.system', ''),
            'valueQuantityCode' => data_get($data, 'value.valueQuantity.code', ''),
            'comment' => data_get($data, 'comment', ''),
            'issuedDate' => data_get($data, 'issuedDate'),
            'issuedTime' => data_get($data, 'issuedTime'),
            'effectiveType' => data_get($data, 'effectivePeriodStartDate') ? 'period' : 'date_time',
            'effectiveDate' => data_get($data, 'effectiveDate', ''),
            'effectiveTime' => data_get($data, 'effectiveTime', ''),
            'effectivePeriodRange' => implode(' — ', array_filter([
                data_get($data, 'effectivePeriodStartDate', ''),
                data_get($data, 'effectivePeriodEndDate', '')
            ])),
            'effectivePeriodStartTime' => data_get($data, 'effectivePeriodStartTime', ''),
            'effectivePeriodEndTime' => data_get($data, 'effectivePeriodEndTime', ''),
            'reactionOn' => data_get($data, 'reactionOn.identifier.value', ''),
            'deviceId' => data_get($data, 'device.identifier.value', ''),
            'specimenId' => data_get($data, 'specimen.identifier.value', ''),
            'components' => $this->componentsFromFhir(data_get($data, 'components', []))
        ];

        if (($valueCode = data_get($data, 'value.valueCodeableConcept.coding.0.code')) !== null) {
            $flat['valueCodeableConcept'] = $valueCode;
            $flat['dictionaryName'] = data_get($data, 'value.valueCodeableConcept.coding.0.system', '');
        }

        if (($valueString = data_get($data, 'value.valueString')) !== null) {
            $flat['valueString'] = $valueString;
        }

        if (($valueBoolean = data_get($data, 'value.valueBoolean')) !== null) {
            $flat['valueBoolean'] = $valueBoolean;
        }

        if (($valueDateTime = data_get($data, 'value.valueDateTime')) !== null) {
            $flat['valueDate'] = convertToAppDateFormat($valueDateTime);
            $flat['valueTime'] = CarbonImmutable::parse($valueDateTime)->format('H:i');
        }

        if (($valueTime = data_get($data, 'value.valueTime')) !== null) {
            $flat['valueTime'] = CarbonImmutable::parse($valueTime)->format('H:i');
        }

        return $flat;
    }

    /**
     * Convert FHIR components to flat component structures.
     *
     * @param  array  $components
     * @return array
     */
    private function componentsFromFhir(array $components): array
    {
        if (empty($components)) {
            return [
                [
                    'codeCode' => '',
                    'codeSystem' => 'eHealth/ICF/qualifiers',
                    'valueCode' => '',
                    'valueSystem' => '',
                    'valueQuantityValue' => '',
                    'valueQuantityCode' => '',
                    'interpretationCode' => '',
                ]
            ];
        }

        return collect($components)
            ->map(fn (array $component) => [
                'codeCode' => data_get($component, 'code.coding.0.code', ''),
                'codeSystem' => data_get($component, 'code.coding.0.system', 'eHealth/ICF/qualifiers'),
                'valueCode' => data_get($component, 'value.valueCodeableConcept.coding.0.code'),
                'valueSystem' => data_get($component, 'value.valueCodeableConcept.coding.0.system'),
                'valueQuantityValue' => data_get($component, 'value.valueQuantity.value', ''),
                'valueQuantityCode' => data_get($component, 'value.valueQuantity.code', ''),
                'interpretationCode' => data_get($component, 'interpretation.coding.0.code', '')
            ])
            ->toArray();
    }
}
