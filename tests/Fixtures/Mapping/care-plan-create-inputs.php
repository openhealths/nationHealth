<?php

declare(strict_types=1);

$form = [
    'category' => '736382003', 'title' => 'План лікування', 'periodStart' => '05.10.2026',
    'periodEnd' => '', 'encounter' => '', 'episodes' => [], 'description' => '', 'note' => '',
    'termsOfService' => 'OUTPATIENT', 'informWith' => '',
];
$context = [
    'id' => '11111111-1111-4111-8111-111111111111',
    'employeeUuid' => '22222222-2222-4222-8222-222222222222',
    'encounterData' => [],
];

return [
    'minimal' => ['form' => $form, ...$context],
    'full_sparse_lists' => ['form' => array_replace($form, [
        'title' => "План \"А\"\nПродовження", 'periodEnd' => '15.10.2026',
        'encounter' => '33333333-3333-4333-8333-333333333333',
        'episodes' => [2 => ['uuid' => 'episode-a'], 5 => ['id' => 'episode-b'], 8 => ['name' => 'ignored']],
        'description' => 'Опис', 'note' => 'Нотатка', 'informWith' => 'auth-method',
        'coAuthors' => ['unused-author'], 'medicalRecords' => [['uuid' => 'unused-record']],
        'periodStartTime' => '22:30', 'periodEndTime' => '03:15',
    ]), ...array_replace($context, ['encounterData' => ['addresses' => [
        4 => ['coding' => [['system' => 'eHealth/ICD10', 'code' => 'A01']], 'text' => 'Діагноз'],
        9 => ['coding' => [['system' => 'eHealth/ICPC2', 'code' => 'D02']]],
    ]]])],
    'encounter_later_same_day' => ['form' => $form, ...array_replace($context, [
        'encounterData' => ['period_start' => '2026-10-05T12:45:30Z'],
    ])],
    'selected_day_after_encounter' => ['form' => array_replace($form, ['periodStart' => '06.10.2026']),
        ...array_replace($context, ['encounterData' => ['period_start' => '2026-10-05T12:45:30Z']])],
    'exact_encounter_midnight' => ['form' => $form, ...array_replace($context, [
        'encounterData' => ['period_start' => '2026-10-04T21:00:00Z'],
    ])],
    'spring_dst' => ['form' => array_replace($form, ['periodStart' => '29.03.2026', 'periodEnd' => '29.03.2026']), ...$context],
    'autumn_dst' => ['form' => array_replace($form, ['periodStart' => '25.10.2026', 'periodEnd' => '25.10.2026']), ...$context],
    'legacy_empty_values' => ['form' => array_replace($form, [
        'description' => '0', 'note' => '0', 'informWith' => '0',
        'episodes' => [['uuid' => '', 'id' => 'fallback-id'], ['uuid' => '0'], ['id' => 'episode-c']],
    ]), ...array_replace($context, ['employeeUuid' => null, 'encounterData' => ['addresses' => [
        ['coding' => [['system' => 'literalSystem', 'code' => '0']], 'unexpectedKey' => false],
    ]]])],
];
