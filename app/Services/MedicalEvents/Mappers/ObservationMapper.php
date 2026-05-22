<?php

declare(strict_types=1);

namespace App\Services\MedicalEvents\Mappers;

use App\Contracts\FhirMapperContract;
use App\Enums\Person\ObservationStatus;
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
            'context' => FhirResource::make()
                ->coding('eHealth/resources', 'encounter')
                ->toIdentifier($uuids['encounter']),
            'status' => ObservationStatus::VALID->value,
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

        // todo: add diagnostic report

        if (!empty($data['effectiveDate']) && !empty($data['effectiveTime'])) {
            $result['effectiveDateTime'] = convertToEHealthISO8601(
                $data['effectiveDate'] . ' ' . $data['effectiveTime']
            );
        }

        if ($data['primarySource']) {
            $result['performer'] = FhirResource::make()
                ->coding('eHealth/resources', 'employee')
                ->toIdentifier($uuids['employee']);
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

        $result = array_merge($result, $this->buildValue($data));

        // todo: add reaction_on

        $fhirComponents = collect($data['components'] ?? [])
            ->filter(fn (array $component) => !empty($component['valueCode']))
            ->map(fn (array $component) => array_merge(
                [
                    'code' => FhirResource::make()
                        ->coding($component['codeSystem'] ?? 'eHealth/ICF/qualifiers', $component['codeCode'])
                        ->toCodeableConcept(),
                    'interpretation' => FhirResource::make()
                        ->coding('eHealth/observation_interpretations', $component['interpretationCode'])
                        ->toCodeableConcept(),
                ],
                $this->buildValue([
                    'valueCodeableConcept' => $component['valueCode'],
                    'dictionaryName' => $component['valueSystem']
                ])
            ))
            ->values()
            ->toArray();

        if (!empty($fhirComponents)) {
            $result['components'] = $fhirComponents;
        }

        // todo: add device

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
            'codingSystem' => $codingSystem,
            'categorySystem' => $categorySystem,
            'codeSystem' => data_get($data, 'code.coding.0.system'),
            'primarySource' => data_get($data, 'primarySource'),
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
            'effectiveDate' => data_get($data, 'effectiveDate', ''),
            'effectiveTime' => data_get($data, 'effectiveTime', ''),
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
                'interpretationCode' => data_get($component, 'interpretation.coding.0.code', '')
            ])
            ->toArray();
    }
}
