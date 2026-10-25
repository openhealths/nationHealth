<?php

declare(strict_types=1);
$base = ['uuid' => 'immunization', 'notGiven' => false, 'vaccineCode' => 'COVID19', 'date' => '05.10.2026', 'time' => '10:15', 'primarySource' => true];
$full = $base + ['performerEmployeeId' => 'performer', 'manufacturer' => 'Виробник', 'lotNumber' => '001', 'expirationDate' => '05.11.2026', 'expirationTime' => '09:32', 'siteCode' => 'arm', 'routeCode' => 'injection', 'doseQuantityValue' => 1.5, 'doseQuantityUnit' => 'ml', 'doseQuantityCode' => 'ml', 'reasons' => [42 => ['code' => 'medical'], 90 => ['code' => '0']], 'vaccinationProtocols' => [42 => ['authorityCode' => 'WHO', 'targetDiseaseCodes' => [9 => 'COVID19', 42 => '', 90 => 'COVID19'], 'doseSequence' => 2, 'series' => 'Серія', 'seriesDoses' => 3, 'description' => 'Опис']]];
$inbound = ['uuid' => 'immunization', 'status' => 'completed', 'notGiven' => false, 'primarySource' => true, 'vaccineCode' => ['coding' => [['code' => 'COVID19']]], 'date' => '05.10.2026 10:15', 'time' => '10:15', 'expirationDate' => '05.11.2026 09:32', 'explanation' => ['reasons' => [42 => ['coding' => [['code' => 'medical']]], 90 => ['coding' => [['code' => '0']]]]], 'vaccinationProtocols' => [42 => ['authority' => ['coding' => [['code' => 'WHO']]], 'targetDiseases' => [9 => ['coding' => [['code' => 'COVID19']]], 42 => ['coding' => [['code' => 'COVID19']]]], 'doseSequence' => 2, 'series' => 'Серія', 'seriesDoses' => 3, 'description' => 'Опис']]];
$minimalIn = ['date' => '05.10.2026 10:15', 'time' => '10:15'];

return [
    'minimal' => ['outbound' => $base, 'inbound' => $minimalIn],
    'full sparse duplicate disease' => ['outbound' => $full, 'inbound' => $inbound],
    'external source' => ['outbound' => array_replace($base, ['primarySource' => false, 'reportOriginCode' => 'other', 'reportOriginText' => 'Опис']), 'inbound' => $minimalIn + ['primarySource' => false, 'reportOrigin' => ['coding' => [['code' => 'other']], 'text' => 'Опис']]],
    'not given' => ['outbound' => array_replace($base, ['notGiven' => true, 'reasonNotGivenCode' => 'contraindication']), 'inbound' => $minimalIn + ['notGiven' => true, 'explanation' => ['reasonsNotGiven' => [['coding' => [['code' => 'contraindication']]]]]]],
    'expiration caller fallback' => ['outbound' => $base + ['expirationDate' => '05.11.2026'], 'inbound' => $minimalIn + ['expirationDate' => '05.11.2026 12:00']],
    'null performer fallback' => ['outbound' => $base + ['performerEmployeeId' => null], 'inbound' => $minimalIn + ['performer' => ['identifier' => ['value' => null]]]],
    'empty performer retained' => ['outbound' => $base + ['performerEmployeeId' => ''], 'inbound' => $minimalIn],
    'null scalars' => ['outbound' => $base, 'inbound' => $minimalIn + ['status' => null, 'notGiven' => null, 'manufacturer' => null, 'vaccinationProtocols' => [42 => ['doseSequence' => null, 'seriesDoses' => 0]]]],
    'empty filtered reasons' => ['outbound' => $base + ['reasons' => [['code' => '0'], ['code' => '']]], 'inbound' => $minimalIn + ['explanation' => ['reasons' => [['coding' => [['code' => '0']]]]]]],
    'false-like optional values' => ['outbound' => $base + ['doseQuantityValue' => 0, 'manufacturer' => '0', 'lotNumber' => '', 'siteCode' => '0', 'routeCode' => '0', 'vaccinationProtocols' => [['authorityCode' => 'WHO', 'targetDiseaseCodes' => ['0', ''], 'doseSequence' => 0, 'series' => '0', 'seriesDoses' => 0, 'description' => '']]], 'inbound' => $minimalIn + ['doseQuantity' => ['value' => 0]]],
    'DST' => ['outbound' => array_replace($full, ['date' => '25.10.2026', 'time' => '04:15']), 'inbound' => $inbound],
];
