<?php

declare(strict_types=1);
$base = ['uuid' => 'observation', 'categorySystem' => 'eHealth/observation_categories', 'categoryCode' => 'laboratory', 'codeSystem' => 'eHealth/LOINC/observation_codes', 'codeCode' => '111', 'issuedDate' => '05.10.2026', 'issuedTime' => '10:15', 'primarySource' => true];
$in = ['uuid' => 'observation', 'categories' => [['coding' => [['system' => 'eHealth/observation_categories', 'code' => 'laboratory']]]], 'code' => ['coding' => [['system' => 'eHealth/LOINC/observation_codes', 'code' => '111']]]];
$full = $base + ['performerEmployeeId' => 'performer', 'effectiveDate' => '05.10.2026', 'effectiveTime' => '10:00', 'interpretationCode' => 'normal', 'comment' => 'Опис', 'methodCode' => 'method', 'bodySiteCode' => 'arm', 'reactionOn' => 'immunization', 'deviceId' => 'equipment', 'specimenId' => 'specimen', 'components' => [42 => ['codeCode' => 'component', 'valueCode' => 'value', 'valueSystem' => 'custom', 'interpretationCode' => 'normal'], 90 => ['codeCode' => 'skipped', 'valueCode' => '0']], 'valueQuantityValue' => 1.5, 'valueQuantityComparator' => '<', 'valueQuantityUnit' => 'ml', 'valueQuantitySystem' => 'units', 'valueQuantityCode' => 'ml'];
$cases = [
 'minimal' => [$base, $in],
 'full sparse components' => [$full, $in + ['primarySource' => true, 'performer' => [['identifier' => ['value' => 'performer']]], 'value' => ['valueQuantity' => ['value' => 1.5, 'comparator' => '<', 'unit' => 'ml', 'system' => 'units', 'code' => 'ml']], 'components' => [42 => ['code' => ['coding' => [['code' => 'component']]], 'value' => ['valueCodeableConcept' => ['coding' => [['code' => 'value', 'system' => 'custom']]]], 'interpretation' => ['coding' => [['code' => 'normal']]]]]]],
 'period single date' => [$base + ['effectiveType' => 'period', 'effectivePeriodRange' => '05.10.2026', 'effectivePeriodStartTime' => '10:00', 'effectivePeriodEndTime' => '11:00'], $in + ['effectivePeriodStartDate' => '05.10.2026', 'effectivePeriodEndDate' => '05.10.2026', 'effectivePeriodStartTime' => '10:00', 'effectivePeriodEndTime' => '11:00']],
 'period range' => [$base + ['effectiveType' => 'period', 'effectivePeriodRange' => '05.10.2026 — 06.10.2026', 'effectivePeriodStartTime' => '10:00', 'effectivePeriodEndTime' => '11:00'], $in + ['effectivePeriodStartDate' => '05.10.2026', 'effectivePeriodEndDate' => '06.10.2026']],
 'empty period' => [$base + ['effectiveType' => 'period', 'effectivePeriodRange' => ''], $in],
 'external source' => [array_replace($base, ['primarySource' => false, 'reportOriginCode' => 'other', 'reportOriginText' => 'Опис']), $in + ['performer' => ['identifier' => ['value' => 'single']], 'primarySource' => false]],
 'performer empty fallback' => [$base + ['performerEmployeeId' => ''], $in + ['performer' => [0 => ['identifier' => ['value' => null]], 'identifier' => ['value' => 'unused']]]],
 'zero false optional' => [$base + ['valueQuantityValue' => 0, 'valueString' => '', 'valueBoolean' => false, 'methodCode' => '0', 'interpretationCode' => '0', 'bodySiteCode' => '0', 'deviceId' => '0', 'specimenId' => '0'], $in + ['status' => null, 'value' => ['valueString' => '', 'valueBoolean' => false, 'valueQuantity' => ['value' => 0]], 'components' => [42 => []]]],
 'empty concept' => [$base + ['valueCodeableConcept' => '', 'dictionaryName' => 'custom'], $in + ['value' => ['valueCodeableConcept' => ['coding' => [['code' => '', 'system' => 'custom']]]]]],
 'date time value' => [$base + ['valueDate' => '05.10.2026', 'valueTime' => '10:00'], $in + ['value' => ['valueDateTime' => '2026-10-05T07:00:00Z']]],
 'time only value' => [$base + ['valueTime' => '10:00'], $in + ['value' => ['valueTime' => '10:00:00']]],
 'time overrides datetime' => [$base + ['valueDate' => '05.10.2026', 'valueTime' => '10:00'], $in + ['value' => ['valueDateTime' => '2026-10-05T07:00:00Z', 'valueTime' => '11:00:00']]],
 'sampled data zero' => [$base + ['valueSampledData' => true, 'valueSampledDataOrigin' => 0, 'valueSampledDataPeriod' => 1.0, 'valueSampledDataFactor' => 0, 'valueSampledDataLowerLimit' => 0, 'valueSampledDataUpperLimit' => 3, 'valueSampledDataDimensions' => 1, 'valueSampledDataData' => '0 1'], $in + ['value' => ['valueSampledData' => ['data' => '0 1']]]],
 'coexisting value variants' => [$full + ['valueCodeableConcept' => 'value', 'dictionaryName' => 'custom', 'valueString' => 'text', 'valueBoolean' => false, 'valueDate' => '05.10.2026', 'valueTime' => '10:00'], $in + ['value' => ['valueCodeableConcept' => ['coding' => [['code' => 'value', 'system' => 'custom']]], 'valueString' => 'text', 'valueBoolean' => false, 'valueDateTime' => '2026-10-05T07:00:00Z']]],
 'null values excluded' => [$base + ['valueString' => null, 'valueBoolean' => null, 'valueCodeableConcept' => null], $in + ['value' => ['valueString' => null, 'valueBoolean' => null, 'valueCodeableConcept' => ['coding' => [['code' => null]]]]]],
 'ICF category' => [array_replace($base, ['categorySystem' => 'eHealth/ICF/observation_categories']), array_replace($in, ['categories' => [['coding' => [['system' => 'eHealth/ICF/observation_categories']]]]])],
 'custom code' => [array_replace($base, ['codeSystem' => 'eHealth/custom/observation_codes']), array_replace($in, ['code' => ['coding' => [['system' => 'eHealth/custom/observation_codes']]]])],
 'DST' => [array_replace($full, ['issuedDate' => '25.10.2026', 'issuedTime' => '04:15']), $in],
];

return array_map(static fn (array $pair): array => ['outbound' => $pair[0], 'inbound' => $pair[1]], $cases);
