<?php

declare(strict_types=1);

$base = [
    'uuid' => 'association-fixed', 'deviceId' => 'device', 'status' => 'attached',
    'primarySource' => true, 'recorded' => '2026-10-05T07:15:00Z',
];
$full = $base + ['associationDate' => '2026-10-04', 'bodySiteCode' => 'site', 'bodySiteText' => 'Ділянка'];
$inbound = [
    'uuid' => 'stored', 'device' => ['identifier' => ['value' => 'device']], 'status' => 'attached',
    'associationDate' => '2026-10-04', 'recorded' => '2026-10-05T07:15:00Z', 'primarySource' => true,
    'bodySite' => ['coding' => [['code' => 'site']], 'text' => 'Ділянка'],
    'reportOrigin' => ['coding' => [['code' => 'patient']], 'text' => 'Пацієнт'],
];

return [
    'full' => ['outbound' => $full, 'inbound' => $inbound],
    'minimal' => ['outbound' => $base, 'inbound' => []],
    'secondary with texts' => ['outbound' => array_replace($full, [
        'primarySource' => false, 'reportOriginCode' => 'patient', 'reportOriginText' => 'Пацієнт',
    ]), 'inbound' => array_replace($inbound, ['primarySource' => false])],
    'secondary without texts' => ['outbound' => array_replace($base, [
        'primarySource' => false, 'bodySiteCode' => 'site', 'reportOriginCode' => 'patient',
    ]), 'inbound' => ['id' => 'not-used-as-uuid', 'bodySite' => [], 'reportOrigin' => []]],
    'empty optional values' => ['outbound' => $base + ['associationDate' => '', 'bodySiteCode' => '0'], 'inbound' => ['associationDate' => '', 'recorded' => '']],
    'null texts' => ['outbound' => array_replace($full, [
        'bodySiteText' => null, 'primarySource' => false, 'reportOriginCode' => 'patient', 'reportOriginText' => null,
    ]), 'inbound' => [
        'uuid' => null, 'status' => null, 'primarySource' => null, 'recorded' => null,
        'bodySite' => ['coding' => [['code' => null]], 'text' => null],
        'reportOrigin' => ['coding' => [['code' => null]], 'text' => null],
        'device' => ['identifier' => ['value' => null]],
    ]],
    'zero primary and report code' => ['outbound' => array_replace($base, [
        'primarySource' => 0, 'reportOriginCode' => '0', 'reportOriginText' => '0',
    ]), 'inbound' => ['recorded' => '0', 'primarySource' => 0, 'bodySite' => ['text' => '0']]],
    'timezone date' => ['outbound' => array_replace($full, ['associationDate' => '2026-10-25T00:15:00+03:00', 'recorded' => '2026-10-25T04:15:00+02:00']), 'inbound' => array_replace($inbound, ['associationDate' => '2026-10-25T00:15:00+03:00'])],
    'unknown fields' => ['outbound' => $full + ['password' => 'secret'], 'inbound' => $inbound + ['unknown' => ['code' => 'ignored']]],
    'null required scalar fields' => ['outbound' => array_replace($base, ['status' => null, 'primarySource' => null, 'reportOriginCode' => 'patient']), 'inbound' => ['status' => null, 'primarySource' => null]],
];
