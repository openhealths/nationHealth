<?php

declare(strict_types=1);
$base = ['uuid' => 'impression', 'codeCode' => 'consultation', 'effectivePeriodStartDate' => '05.10.2026', 'effectivePeriodStartTime' => '10:00', 'effectivePeriodEndDate' => '05.10.2026', 'effectivePeriodEndTime' => '11:00'];
$full = $base + ['assessorEmployeeId' => 'assessor', 'description' => 'Опис', 'previous' => [['id' => 'previous']], 'problems' => [42 => ['id' => 'problem'], 90 => ['id' => 'problem']], 'summary' => 'Підсумок', 'findings' => [42 => ['id' => 'finding', 'type' => 'observation', 'basis' => 'Причина'], 90 => ['id' => 'problem', 'type' => 'condition', 'basis' => '0']], 'supportingInfo' => [42 => ['uuid' => 'support', 'type' => 'procedure'], 90 => ['uuid' => 'support', 'type' => 'procedure']], 'note' => 'Примітка'];
$ref = static fn (string $id, string $type): array => ['identifier' => ['value' => $id, 'type' => ['coding' => [['code' => $type]]]]];
$in = ['uuid' => 'impression', 'status' => 'completed', 'code' => ['coding' => [['code' => 'consultation']]], 'assessor' => $ref('assessor', 'employee'), 'effectivePeriodStartDate' => '05.10.2026', 'effectivePeriodStartTime' => '10:00', 'effectivePeriodEndDate' => '05.10.2026', 'effectivePeriodEndTime' => '11:00', 'previous' => $ref('previous', 'clinical_impression'), 'problems' => [42 => $ref('problem', 'condition')], 'findings' => [42 => ['itemReference' => $ref('finding', 'observation'), 'basis' => 'Причина']], 'supportingInfo' => [42 => $ref('support', 'procedure'), 90 => $ref('support', 'procedure')]];

return [
 'minimal' => ['outbound' => $base, 'inbound' => []],
 'full sparse duplicates' => ['outbound' => $full, 'inbound' => $in],
 'null fallback assessor' => ['outbound' => $base + ['assessorEmployeeId' => null], 'inbound' => ['assessor' => ['identifier' => ['value' => null]]]],
 'empty assessor retained' => ['outbound' => $base + ['assessorEmployeeId' => ''], 'inbound' => []],
 'false-like optional values' => ['outbound' => $base + ['description' => '0', 'summary' => '0', 'note' => '0', 'previous' => [], 'problems' => [], 'findings' => [], 'supportingInfo' => []], 'inbound' => ['status' => null, 'description' => null, 'summary' => 0, 'note' => false]],
 'missing reference metadata' => ['outbound' => $base, 'inbound' => ['previous' => $ref('unknown', 'clinical_impression'), 'problems' => [42 => $ref('unknown', 'condition')], 'findings' => [90 => ['itemReference' => $ref('unknown', 'observation'), 'basis' => null]], 'supportingInfo' => [42 => $ref('unknown', 'encounter')]]],
 'DST' => ['outbound' => array_replace($full, ['effectivePeriodStartDate' => '25.10.2026', 'effectivePeriodStartTime' => '04:15', 'effectivePeriodEndDate' => '25.10.2026', 'effectivePeriodEndTime' => '05:15']), 'inbound' => $in],
];
