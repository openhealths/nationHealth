<?php

declare(strict_types=1);

$base = ['primarySource' => true, 'deviceId' => 'device'];

return [
    'opening and closing sparse pair' => [
        8 => $base + ['uuid' => 'opening', 'status' => 'attached'],
        42 => $base + ['uuid' => 'closing', 'status' => 'unattached'],
    ],
    'implanted pair plus another device' => [
        4 => $base + ['uuid' => 'opening', 'status' => 'implanted', 'recorded' => ''],
        11 => $base + ['uuid' => 'closing', 'status' => 'explanted', 'recorded' => null],
        28 => array_replace($base, ['uuid' => 'other', 'deviceId' => 'other-device', 'status' => 'attached']),
    ],
    'existing recorded is untouched and counted in pair' => [
        6 => $base + ['uuid' => 'existing', 'status' => 'attached', 'recorded' => '2026-10-01T07:10:00Z'],
        19 => $base + ['uuid' => 'new', 'status' => 'implanted'],
    ],
    'single opening uses current time' => [$base + ['uuid' => 'single', 'status' => 'attached']],
    'zero time and entered in error' => [$base + ['uuid' => 'error', 'status' => 'entered_in_error', 'recorded' => '0']],
    'empty' => [],
];
