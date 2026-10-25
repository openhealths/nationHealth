<?php

declare(strict_types=1);

$subject = '20000000-0000-4000-8000-000000000002';
$focus = '50000000-0000-4000-8000-000000000005';
$previous = [
    'identifier' => ['value' => '70000000-0000-4000-8000-000000000007'],
    'subject' => ['value' => $subject],
    'section' => ['focus' => ['value' => $subject]],
    'event' => [['period' => ['start' => '2026-10-24T00:00:01Z', 'end' => '2026-10-25T20:59:59Z']]],
    'extension' => [
        ['valueCode' => 'IS_ACCIDENT', 'valueBoolean' => true],
        ['valueCode' => 'IS_INTOXICATED', 'valueBoolean' => false],
        ['valueCode' => 'IS_FOREIGN_TREATMENT', 'valueBoolean' => true],
        ['valueCode' => 'IS_FORCE_RENEW', 'valueBoolean' => false],
        ['valueCode' => 'TREATMENT_VIOLATION', 'valueString' => 'reject_recommendation'],
        ['valueCode' => 'TREATMENT_VIOLATION_DATE', 'valueDate' => '2026-10-24'],
    ],
];

$cases = [
    'full resource' => ['previous' => $previous],
    'separate care recipient' => ['previous' => [...$previous, 'section' => ['focus' => ['value' => $focus, 'type' => ['coding' => [['system' => 'resource', 'code' => 'person']]]]]]],
    'identified subject changes own focus' => ['previous' => $previous, 'subjectUuid' => '10000000-0000-4000-8000-000000000001'],
    'missing focus preserves current focus' => ['previous' => array_diff_key($previous, ['section' => true])],
    'empty focus preserves current focus' => ['previous' => [...$previous, 'section' => ['focus' => ['value' => '']]]],
    'missing document' => ['previous' => []],
    'null periods and extensions' => ['previous' => [...$previous, 'event' => [['period' => ['start' => null, 'end' => null]]], 'extension' => null]],
    'duplicate extension last false wins' => ['previous' => [...$previous, 'extension' => [
        ['valueCode' => 'IS_ACCIDENT', 'valueBoolean' => true],
        ['valueCode' => 'IS_ACCIDENT', 'valueBoolean' => false],
        'ignored non-object',
        ['valueBoolean' => true],
        ['valueCode' => 'TREATMENT_VIOLATION', 'valueString' => '0'],
        ['valueCode' => 'UNRECOGNIZED', 'valueBoolean' => true],
    ]]],
    'fractional seconds across midnight' => ['previous' => [...$previous, 'event' => [['period' => ['start' => '2026-03-29T23:30:00.123456Z', 'end' => '2026-03-30T23:30:00.654321Z']]]]],
    'configured ISO calendar' => ['previous' => $previous, 'dateFormat' => 'Y-m-d', 'timezone' => 'UTC'],
];
$result = [];
foreach ($cases as $name => $case) {
    foreach (['replaces', 'appends'] as $mode) {
        $result[$name.' '.$mode] = $case + ['mode' => $mode, 'subjectUuid' => $subject, 'sectionFocusUuid' => $focus];
    }
}

return $result;
