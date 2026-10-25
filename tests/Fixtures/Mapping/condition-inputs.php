<?php

declare(strict_types=1);
$base = ['uuid' => 'condition', 'primarySource' => true, 'codeSystem' => 'eHealth/ICPC2/condition_codes', 'codeCode' => 'D02', 'clinicalStatus' => 'active', 'verificationStatus' => 'confirmed', 'onsetDate' => '05.10.2026', 'onsetTime' => '10:15'];
$full = $base + ['asserterEmployeeId' => 'asserter', 'asserterText' => 'Лікар', 'severityCode' => 'severe', 'bodySites' => [9 => ['code' => 'arm'], 42 => ['code' => '0']], 'stageCode' => 'stage', 'assertedDate' => '05.10.2026', 'assertedTime' => '11:00', 'evidenceCodes' => [9 => ['code' => 'A01', 'text' => 'Опис'], 42 => ['code' => 'D02', 'system' => 'eHealth/ICPC2/condition_codes']], 'evidenceDetails' => [42 => ['id' => 'evidence', 'type' => 'observation']]];
$inbound = ['uuid' => 'condition', 'primarySource' => true, 'code' => ['coding' => [['system' => 'eHealth/ICPC2/condition_codes', 'code' => 'D02']]], 'clinicalStatus' => 'active', 'verificationStatus' => 'confirmed', 'onsetDate' => '05.10.2026 10:15', 'asserter' => [['identifier' => ['value' => 'asserter', 'type' => ['text' => 'Лікар']]]], 'assertedDate' => '05.10.2026 11:00', 'bodySites' => [42 => ['coding' => [['code' => 'arm']]]], 'evidences' => [['codes' => [9 => ['coding' => [['code' => 'A01', 'system' => 'eHealth/ICPC2/reasons']]]], 'details' => [42 => ['identifier' => ['value' => 'evidence']]]]]];

return [
 'minimal' => ['outbound' => $base, 'inbound' => []],
 'full sparse' => ['outbound' => $full, 'inbound' => $inbound],
 'external origin' => ['outbound' => array_replace($base, ['primarySource' => false, 'reportOriginCode' => 'other']), 'inbound' => ['primarySource' => false, 'reportOrigin' => ['coding' => [['code' => 'other']]]]],
 'asserter missing fallback' => ['outbound' => $base, 'inbound' => ['asserter' => ['identifier' => ['value' => 'employee', 'type' => ['text' => 'Fallback']]]]],
 'asserter null fallback' => ['outbound' => $base + ['asserterEmployeeId' => null], 'inbound' => ['asserter' => [0 => ['identifier' => ['value' => null, 'type' => ['text' => null]]], 'identifier' => ['value' => 'unused']]]],
 'empty filtered body sites' => ['outbound' => $base + ['bodySites' => [['code' => '0'], ['code' => '']]], 'inbound' => ['bodySites' => [42 => []]]],
 'explicit scalar null' => ['outbound' => $base, 'inbound' => ['code' => ['coding' => [['code' => null, 'system' => null]]], 'severity' => ['coding' => [['code' => null]]], 'assertedDate' => null]],
 'incomplete asserted time' => ['outbound' => $base + ['assertedDate' => '05.10.2026', 'assertedTime' => ''], 'inbound' => $inbound],
 'false-like optional values' => ['outbound' => $base + ['severityCode' => '0', 'stageCode' => '0', 'evidenceCodes' => [], 'evidenceDetails' => []], 'inbound' => ['evidences' => [['codes' => [], 'details' => []]]]],
 'DST' => ['outbound' => array_replace($full, ['onsetDate' => '25.10.2026', 'onsetTime' => '04:15']), 'inbound' => $inbound],
];
