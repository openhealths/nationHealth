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
                // todo must me array of categories, in frontend now can choose only 1 category
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
                ->toCodeableConcept();
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

        // todo: add reference_ranges

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
            $result['components'] = $fhirComponents;
        }

        // todo: add specimen

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
            'primarySource' => data_get($data, 'primarySource', true),
            'reportOriginCode' => data_get($data, 'reportOrigin.coding.0.code', ''),
            'categoryCode' => data_get($data, 'categories.0.coding.0.code', ''),
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
            'issuedDate' => data_get($data, 'issuedDate', ''),
            'issuedTime' => substr(data_get($data, 'issuedTime', ''), 0, 5),
            'effectiveDate' => data_get($data, 'effectiveDate', ''),
            'effectiveTime' => substr(data_get($data, 'effectiveTime', ''), 0, 5),
            'components' => $this->componentsFromFhir(data_get($data, 'components', [])),
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
            $valueParsed = CarbonImmutable::parse($valueDateTime);
            $flat['valueDate'] = $valueParsed->format('Y-m-d');
            $flat['valueTime'] = $valueParsed->format('H:i');
        }

        if (($valueTime = data_get($data, 'value.valueTime')) !== null) {
            $flat['valueTime'] = substr($valueTime, 0, 5);
        }

        return $flat;
    }

    /**
     * Prepare observation data for list/detail display.
     * Works with data returned both from eHealth API and from SQL relations.
     *
     * @param  array  $data
     * @param  array  $dictionaries
     * @return array
     */
    public function forList(array $data, array $dictionaries = []): array
    {
        $data['valueDisplay'] = $this->valueDisplay($data, $dictionaries);

        return $data;
    }

    /**
     * Build a human-readable value for an observation.
     *
     * @param  array  $data
     * @param  array  $dictionaries
     * @return string
     */
    public function valueDisplay(array $data, array $dictionaries = []): string
    {
        $ownValue = $this->singleValueDisplay($data, $dictionaries);

        if ($ownValue !== null) {
            return $ownValue;
        }

        $components = collect(data_get($data, 'components', []))
            ->map(fn (array $component) => $this->componentValueDisplay($component, $dictionaries))
            ->filter(fn (?string $value) => filled($value))
            ->values()
            ->implode('; ');

        return $components !== '' ? $components : '-';
    }

    /**
     * Build a human-readable value for one component.
     *
     * @param  array  $component
     * @param  array  $dictionaries
     * @return string|null
     */
    private function componentValueDisplay(array $component, array $dictionaries): ?string
    {
        $value = $this->singleValueDisplay($component, $dictionaries);

        if ($value === null) {
            return null;
        }

        $label = $this->dictionaryLabel(
            $dictionaries,
            data_get($component, 'code.coding.0.system'),
            data_get($component, 'code.coding.0.code'),
            data_get($component, 'code.text')
        );

        return $label ? $label . ': ' . $value : $value;
    }

    /**
     * Build a human-readable value from any value[x] shape.
     * The source can be a full observation/component or an already nested value relation.
     *
     * @param  array  $source
     * @param  array  $dictionaries
     * @return string|null
     */
    private function singleValueDisplay(array $source, array $dictionaries): ?string
    {
        $value = data_get($source, 'value');

        if (is_array($value) && !empty($value)) {
            $source = $value;
        }

        if (($quantity = data_get($source, 'valueQuantity')) !== null) {
            return $this->quantityDisplay($quantity);
        }

        if (($codeableConcept = data_get($source, 'valueCodeableConcept')) !== null) {
            return $this->dictionaryLabel(
                $dictionaries,
                data_get($codeableConcept, 'coding.0.system'),
                data_get($codeableConcept, 'coding.0.code'),
                data_get($codeableConcept, 'text')
            );
        }

        if (($valueString = data_get($source, 'valueString')) !== null) {
            return (string) $valueString;
        }

        if (($valueBoolean = data_get($source, 'valueBoolean')) !== null) {
            return $valueBoolean ? 'Так' : 'Ні';
        }

        if (($valueDateTime = data_get($source, 'valueDateTime')) !== null) {
            return $this->dateTimeDisplay((string) $valueDateTime);
        }

        if (($valueTime = data_get($source, 'valueTime')) !== null) {
            return substr((string) $valueTime, 0, 5);
        }

        if (($range = data_get($source, 'valueRange')) !== null) {
            return $this->rangeDisplay($range);
        }

        if (($ratio = data_get($source, 'valueRatio')) !== null) {
            return $this->ratioDisplay($ratio);
        }

        if (($sampledData = data_get($source, 'valueSampledData')) !== null) {
            return $this->sampledDataDisplay($sampledData);
        }

        return null;
    }

    /**
     * Build a display label from dictionaries with safe fallback.
     *
     * @param  array  $dictionaries
     * @param  string|null  $system
     * @param  string|null  $code
     * @param  string|null  $fallback
     * @return string|null
     */
    private function dictionaryLabel(
        array $dictionaries,
        ?string $system,
        ?string $code,
        ?string $fallback = null
    ): ?string {
        if (blank($code)) {
            return filled($fallback) ? $fallback : null;
        }

        return data_get($dictionaries, $system . '.' . $code, $fallback ?: $code);
    }

    /**
     * Build a display value for Quantity.
     *
     * @param  array  $quantity
     * @return string|null
     */
    private function quantityDisplay(array $quantity): ?string
    {
        $parts = array_filter([
            data_get($quantity, 'comparator'),
            data_get($quantity, 'value'),
            data_get($quantity, 'unit') ?: data_get($quantity, 'code'),
        ], static fn ($part) => filled($part));

        return !empty($parts) ? implode(' ', $parts) : null;
    }

    /**
     * Build a display value for Range.
     *
     * @param  array  $range
     * @return string|null
     */
    private function rangeDisplay(array $range): ?string
    {
        $low = $this->quantityDisplay(data_get($range, 'low', []));
        $high = $this->quantityDisplay(data_get($range, 'high', []));

        return collect([$low, $high])->filter()->implode(' - ') ?: null;
    }

    /**
     * Build a display value for Ratio.
     *
     * @param  array  $ratio
     * @return string|null
     */
    private function ratioDisplay(array $ratio): ?string
    {
        $numerator = $this->quantityDisplay(data_get($ratio, 'numerator', []));
        $denominator = $this->quantityDisplay(data_get($ratio, 'denominator', []));

        if ($numerator && $denominator) {
            return $numerator . ' / ' . $denominator;
        }

        return $numerator ?: $denominator;
    }

    /**
     * Build a display value for SampledData.
     *
     * @param  array  $sampledData
     * @return string|null
     */
    private function sampledDataDisplay(array $sampledData): ?string
    {
        return data_get($sampledData, 'data')
            ?: collect([
                data_get($sampledData, 'origin'),
                data_get($sampledData, 'period'),
                data_get($sampledData, 'dimensions'),
            ])->filter()->implode(' | ')
            ?: null;
    }

    /**
     * Format date/time value for display.
     *
     * @param  string  $dateTime
     * @return string
     */
    private function dateTimeDisplay(string $dateTime): string
    {
        try {
            return CarbonImmutable::parse($dateTime)->format('d.m.Y H:i');
        } catch (\Throwable) {
            return $dateTime;
        }
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
