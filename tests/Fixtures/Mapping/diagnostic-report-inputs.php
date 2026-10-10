<?php

declare(strict_types=1);
$base = ['codeValue' => 'service', 'categoryCode' => 'laboratory', 'issuedDate' => '05.10.2026', 'issuedTime' => '10:15', 'primarySource' => true];
$full = $base + ['effectiveType' => 'date_time', 'effectiveDate' => '05.10.2026', 'effectiveTime' => '10:00', 'referralType' => 'electronic', 'basedOnIdentifier' => 'referral', 'conclusion' => 'Висновок', 'conclusionCode' => 'D02', 'specimenIds' => [42 => 'specimen', 90 => 'specimen'], 'usedReferences' => [42 => ['id' => 'equipment'], 90 => ['id' => 'equipment']], 'divisionId' => 'division', 'performerEmployeeIds' => [42 => 'performer', 90 => 'interpreter', 99 => 'employee'], 'resultsInterpreterEmployeeId' => 'interpreter'];
$ref = static fn (string $id): array => ['identifier' => ['value' => $id]];
$in = ['uuid' => 'report', 'status' => 'final', 'category' => [['coding' => [['code' => 'laboratory']]]], 'code' => $ref('service'), 'primarySource' => true, 'performer' => [42 => ['reference' => $ref('performer')], 90 => ['reference' => $ref('interpreter')], 99 => ['reference' => $ref('performer')]], 'resultsInterpreter' => ['reference' => $ref('interpreter')], 'specimens' => [42 => $ref('specimen'), 90 => $ref('specimen')], 'usedReferences' => [42 => $ref('equipment')], 'effectiveDateTime' => '2026-10-05T07:00:00Z', 'issuedDate' => '05.10.2026', 'issuedTime' => '10:15'];
$paper = (static fn (): array => require __DIR__.'/paper-referral-inputs.php')();
$paper = reset($paper)['outbound'];

return [
 'minimal' => ['outbound' => $base, 'inbound' => []],
 'full sparse dedup' => ['outbound' => $full, 'inbound' => $in],
 'external source' => ['outbound' => array_replace($base, ['primarySource' => false, 'reportOriginCode' => 'other', 'reportOriginText' => 'Опис', 'resultsInterpreterEmployeeId' => 'interpreter']), 'inbound' => ['primarySource' => false, 'reportOrigin' => ['coding' => [['code' => 'other']], 'text' => 'Опис']]],
 'period start only' => ['outbound' => $base + ['effectiveType' => 'period', 'effectivePeriodStartDate' => '05.10.2026', 'effectivePeriodStartTime' => '10:00', 'effectivePeriodEndDate' => '', 'effectivePeriodEndTime' => ''], 'inbound' => ['effectivePeriodStartDate' => '05.10.2026', 'effectivePeriodStartTime' => '10:00']],
 'period full' => ['outbound' => $base + ['effectiveType' => 'period', 'effectivePeriodStartDate' => '05.10.2026', 'effectivePeriodStartTime' => '10:00', 'effectivePeriodEndDate' => '06.10.2026', 'effectivePeriodEndTime' => '11:00'], 'inbound' => ['effectivePeriodStartDate' => '05.10.2026', 'effectivePeriodStartTime' => '10:00', 'effectivePeriodEndDate' => '06.10.2026', 'effectivePeriodEndTime' => '11:00']],
 'paper and electronic' => ['outbound' => $full + $paper, 'inbound' => $in],
 'false-like filtered references' => ['outbound' => $base + ['usedReferences' => [['id' => '0'], ['id' => '']], 'specimenIds' => [''], 'divisionId' => '0', 'conclusion' => '0', 'conclusionCode' => '0', 'performerEmployeeIds' => ['0', '']], 'inbound' => ['usedReferences' => [42 => $ref('')], 'specimens' => [42 => $ref('0')]]],
 'null scalar and datetime defaults' => ['outbound' => $base, 'inbound' => ['status' => null, 'effectiveDateTime' => '2026-10-05T07:00:00Z', 'effectiveDate' => null, 'effectiveTime' => null, 'conclusion' => null]],
 'null performer list skipped' => ['outbound' => $base + ['performerEmployeeIds' => null], 'inbound' => $in],
 'DST' => ['outbound' => array_replace($full, ['issuedDate' => '25.10.2026', 'issuedTime' => '04:15']), 'inbound' => $in],
];
