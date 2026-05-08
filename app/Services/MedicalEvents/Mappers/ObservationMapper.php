<?php

declare(strict_types=1);

namespace App\Services\MedicalEvents\Mappers;

use App\Enums\Person\ObservationStatus;
use App\Services\MedicalEvents\FhirResource;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

class ObservationMapper
{
    /**
     * Convert a flat form observation to a FHIR structure for persistence/API.
     *
     * @param  array  $observation
     * @param  array  $uuids
     * @return array
     */
    public function toFhir(array $observation, array $uuids): array
    {
        $data = [
            'id' => $observation['uuid'] ?? Str::uuid()->toString(),
            'context' => FhirResource::make()
                ->coding('eHealth/resources', 'encounter')
                ->toIdentifier($uuids['encounter']),
            'status' => ObservationStatus::VALID->value,
            'categories' => [
                FhirResource::make()
                    ->coding($observation['categorySystem'], $observation['categoryCode'])
                    ->toCodeableConcept()
                // todo must me array of categories, in frontend now can choose only 1 category
            ],
            'code' => FhirResource::make()
                ->coding($observation['codeSystem'], $observation['codeCode'])
                ->toCodeableConcept(),
            'issued' => convertToEHealthISO8601($observation['issuedDate'] . ' ' . $observation['issuedTime']),
            'primarySource' => $observation['primarySource']
        ];

        // todo: add diagnostic report

        if (!empty($observation['effectiveDate']) && !empty($observation['effectiveTime'])) {
            $data['effectiveDateTime'] = convertToEHealthISO8601(
                $observation['effectiveDate'] . ' ' . $observation['effectiveTime']
            );
        }

        if ($observation['primarySource']) {
            $data['performer'] = FhirResource::make()
                ->coding('eHealth/resources', 'employee')
                ->toIdentifier($uuids['employee']);
        } else {
            $data['reportOrigin'] = FhirResource::make()
                ->coding('eHealth/report_origins', $observation['reportOriginCode'])
                ->toCodeableConcept();
        }

        if (!empty($observation['interpretationCode'])) {
            $data['interpretation'] = FhirResource::make()
                ->coding('eHealth/observation_interpretations', $observation['interpretationCode'])
                ->toCodeableConcept();
        }

        if (!empty($observation['comment'])) {
            $data['comment'] = $observation['comment'];
        }

        if (!empty($observation['methodCode'])) {
            $data['method'] = FhirResource::make()
                ->coding('eHealth/observation_methods', $observation['methodCode'])
                ->toCodeableConcept();
        }

        if (!empty($observation['bodySiteCode'])) {
            $data['bodySite'] = FhirResource::make()
                ->coding('eHealth/body_sites', $observation['bodySiteCode'])
                ->toCodeableConcept();
        }

        $data = array_merge($data, $this->buildValue($observation));

        // todo: add reference_ranges

        // todo: add reaction_on

        $fhirComponents = collect($observation['components'] ?? [])
            ->filter(fn (array $component) => !empty($component['valueCode']))
            ->map(fn (array $component) => array_merge(
                [
                    'code' => FhirResource::make()
                        ->coding($component['codeSystem'] ?? 'eHealth/ICF/qualifiers', $component['codeCode'])
                        ->toCodeableConcept(),
                    'interpretation' => FhirResource::make()
                        ->coding('eHealth/observation_interpretations', $component['interpretationCode'])
                        ->toCodeableConcept(),
                    // todo: add reference_ranges
                ],
                $this->buildValue([
                    'valueCodeableConcept' => $component['valueCode'],
                    'dictionaryName' => $component['valueSystem']
                ])
            ))
            ->values()
            ->toArray();

        if (!empty($fhirComponents)) {
            $data['components'] = $fhirComponents;
        }

        // todo: add specimen

        // todo: add device

        return $data;
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

        if (!empty($data['valueQuantityValue'])) {
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

        if (isset($data['valueRange'])) {
            $value['valueRange'] = ['low' => '', 'high' => ''];
        }

        if (isset($data['valueRatio'])) {
            $value['valueRatio'] = ['denominator' => '', 'numerator' => ''];
        }

        if (isset($data['valueDate'], $data['valueTime'])) {
            $value['valueDateTime'] = convertToEHealthISO8601(
                $data['valueDate'] . ' ' . $data['valueTime']
            );
        } elseif (isset($data['valueTime'])) {
            $value['valueTime'] = $data['valueTime'] . ':00';
        }

        if (isset($data['valuePeriod'])) {
            $value['valuePeriod'] = ['start' => '', 'end' => ''];
        }

        return $value;
    }

    /**
     * Convert a FHIR observation (from DB) to a flat form structure.
     *
     * @param  array  $observation
     * @return array
     */
    public function fromFhir(array $observation): array
    {
        $categorySystem = data_get($observation, 'categories.0.coding.0.system');
        $codeSystem = data_get($observation, 'code.coding.0.system');
        if (str_contains($categorySystem, 'ICF')) {
            $codingSystem = 'icf';
        } elseif ($codeSystem === 'eHealth/custom/observation_codes') {
            $codingSystem = 'custom';
        } else {
            $codingSystem = 'loinc';
        }

        $flat = [
            'uuid' => data_get($observation, 'uuid'),
            'codingSystem' => $codingSystem,
            'categorySystem' => $categorySystem,
            'codeSystem' => data_get($observation, 'code.coding.0.system'),
            'primarySource' => data_get($observation, 'primarySource', true),
            'reportOriginCode' => data_get($observation, 'reportOrigin.coding.0.code', ''),
            'categoryCode' => data_get($observation, 'categories.0.coding.0.code', ''),
            'codeCode' => data_get($observation, 'code.coding.0.code'),
            'methodCode' => data_get($observation, 'method.coding.0.code', ''),
            'interpretationCode' => data_get($observation, 'interpretation.coding.0.code', ''),
            'bodySiteCode' => data_get($observation, 'bodySite.coding.0.code', ''),
            'valueQuantityValue' => data_get($observation, 'value.valueQuantity.value', ''),
            'valueQuantityComparator' => data_get($observation, 'value.valueQuantity.comparator', ''),
            'valueQuantityUnit' => data_get($observation, 'value.valueQuantity.unit', ''),
            'valueQuantitySystem' => data_get($observation, 'value.valueQuantity.system', ''),
            'valueQuantityCode' => data_get($observation, 'value.valueQuantity.code', ''),
            'comment' => data_get($observation, 'comment', ''),
            'issuedDate' => data_get($observation, 'issuedDate', ''),
            'issuedTime' => substr(data_get($observation, 'issuedTime', ''), 0, 5),
            'effectiveDate' => data_get($observation, 'effectiveDate', ''),
            'effectiveTime' => substr(data_get($observation, 'effectiveTime', ''), 0, 5),
            'components' => $this->componentsFromFhir(data_get($observation, 'components', [])),
        ];

        if (($valueCode = data_get($observation, 'value.valueCodeableConcept.coding.0.code')) !== null) {
            $flat['valueCodeableConcept'] = $valueCode;
            $flat['dictionaryName'] = data_get($observation, 'value.valueCodeableConcept.coding.0.system', '');
        }

        if (($valueString = data_get($observation, 'value.valueString')) !== null) {
            $flat['valueString'] = $valueString;
        }

        if (($valueBoolean = data_get($observation, 'value.valueBoolean')) !== null) {
            $flat['valueBoolean'] = $valueBoolean;
        }

        if (($valueDateTime = data_get($observation, 'value.valueDateTime')) !== null) {
            $valueParsed = CarbonImmutable::parse($valueDateTime);
            $flat['valueDate'] = $valueParsed->format('Y-m-d');
            $flat['valueTime'] = $valueParsed->format('H:i');
        }

        if (($valueTime = data_get($observation, 'value.valueTime')) !== null) {
            $flat['valueTime'] = substr($valueTime, 0, 5);
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
                    'interpretationCode' => '',
                ]
            ];
        }

        return collect($components)
            ->map(fn (array $component) => [
                'codeCode' => data_get($component, 'code.coding.0.code', ''),
                'codeSystem' => data_get($component, 'code.coding.0.system', 'eHealth/ICF/qualifiers'),
                'valueCode' => data_get($component, 'value.valueCodeableConcept.coding.0.code', ''),
                'valueSystem' => data_get($component, 'value.valueCodeableConcept.coding.0.system', ''),
                'interpretationCode' => data_get($component, 'interpretation.coding.0.code', ''),
            ])
            ->toArray();
    }
}
