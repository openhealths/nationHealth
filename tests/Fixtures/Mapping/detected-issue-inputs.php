<?php

declare(strict_types=1);

$base = ['uuid' => 'issue-fixed', 'subjectId' => 'device', 'primarySource' => true];
$full = $base + [
    'status' => 'final', 'authorEmployeeId' => 'author', 'code' => 'issue-code',
    'detail' => "Опис\nДругий рядок", 'identifiedDate' => '05.10.2026', 'identifiedTime' => '10:15',
    'implicatedId' => 'implicated', 'basedOnId' => 'previous',
];
$inbound = [
    'id' => 'remote', 'uuid' => 'local', 'status' => 'final',
    'subject' => ['identifier' => ['value' => 'device']],
    'identifiedDateTime' => '2026-10-05T07:15:00.000Z', 'detail' => 'Опис',
    'code' => ['coding' => [['code' => 'issue-code']]],
    'implicated' => ['identifier' => ['value' => 'implicated']],
    'basedOn' => ['identifier' => ['value' => 'previous']], 'primarySource' => true,
    'author' => ['identifier' => ['value' => 'author']],
    'reportOrigin' => ['coding' => [['code' => 'patient']]],
];

return [
    'full primary' => ['outbound' => $full, 'inbound' => $inbound],
    'minimal and missing defaults' => ['outbound' => $base, 'inbound' => []],
    'non-primary author is an object' => ['outbound' => array_replace($full, [
        'primarySource' => false, 'reportOriginCode' => 'patient',
    ]), 'inbound' => array_replace($inbound, ['primarySource' => false, 'author' => (object) []])],
    'null status and author fallback' => ['outbound' => $base + ['status' => null, 'authorEmployeeId' => null], 'inbound' => [
        'uuid' => null, 'id' => 'must-not-replace-null', 'status' => null, 'primarySource' => null, 'detail' => null,
    ]],
    'empty author does not fallback' => ['outbound' => $base + ['authorEmployeeId' => ''], 'inbound' => ['id' => 'fallback', 'uuid' => '']],
    'optional zero values are omitted' => ['outbound' => $base + [
        'code' => '0', 'detail' => 0, 'identifiedDate' => '05.10.2026', 'identifiedTime' => '0',
        'implicatedId' => '0', 'basedOnId' => 0,
    ], 'inbound' => ['detail' => 0, 'primarySource' => false, 'code' => ['coding' => [['code' => '0']]]]],
    'optional explicit null values' => ['outbound' => $base + [
        'code' => null, 'detail' => null, 'identifiedDate' => null, 'identifiedTime' => null,
        'implicatedId' => null, 'basedOnId' => null,
    ], 'inbound' => [
        'subject' => ['identifier' => ['value' => null]], 'author' => ['identifier' => ['value' => null]],
        'code' => ['coding' => [['code' => null]]], 'identifiedDateTime' => null,
        'implicated' => ['identifier' => ['value' => null]], 'basedOn' => ['identifier' => ['value' => null]],
        'reportOrigin' => ['coding' => [['code' => null]]],
    ]],
    'incomplete date' => ['outbound' => $base + ['identifiedDate' => '05.10.2026'], 'inbound' => ['id' => 'remote', 'identifiedDateTime' => '']],
    'DST boundary' => ['outbound' => array_replace($full, ['identifiedDate' => '25.10.2026', 'identifiedTime' => '04:15']), 'inbound' => ['identifiedDateTime' => '2026-10-25T04:15:00+02:00']],
    'unknown input fields do not leak' => ['outbound' => $full + ['password' => 'secret', 'unrelated' => ['value' => 0]], 'inbound' => $inbound + ['unrelated' => ['value' => 0]]],
    'zero primary source and report code' => ['outbound' => array_replace($base, ['primarySource' => 0, 'reportOriginCode' => '0']), 'inbound' => ['primarySource' => 0]],
    'empty required reference' => ['outbound' => array_replace($base, ['subjectId' => '']), 'inbound' => ['subject' => null]],
    'explicit null primary source' => ['outbound' => array_replace($base, ['primarySource' => null, 'reportOriginCode' => 'patient']), 'inbound' => ['primarySource' => null]],
];
