<?php

declare(strict_types=1);

return [
    'minimal' => ['model' => ['title' => 'Draft'], 'encounterUuid' => null, 'employeeUuid' => null],
    'full' => ['model' => [
        'title' => "План \"А\"\nПродовження", 'category' => ['coding' => [['code' => 'CLASS_23']]],
        'context' => 'context-code', 'period_start' => '2026-03-29', 'period_end' => '2026-10-25',
        'encounter_id' => 17, 'addresses' => [['coding' => [['code' => 'A01', 'system' => 'literalSystem']]]],
        'supporting_info' => ['episodes' => [3 => ['name' => 'Епізод', 'uuid' => 'episode']],
            'medical_records' => [9 => ['name' => 'Документ', 'uuid' => 'record']]],
        'description' => 'Опис', 'note' => 'Note', 'inform_with' => 'SMS',
    ], 'encounterUuid' => '33333333-3333-4333-8333-333333333333', 'employeeUuid' => '22222222-2222-4222-8222-222222222222'],
    'empty_values' => ['model' => [
        'title' => '0', 'category' => '0', 'context' => '0', 'description' => '0', 'note' => '',
        'inform_with' => '0', 'addresses' => [['value' => 0, 'keepFalse' => false]],
        'supporting_info' => ['episodes' => [['name' => '0'], ['name' => '']], 'medical_records' => []],
    ], 'encounterUuid' => null, 'employeeUuid' => null],
    'missing_category_code' => ['model' => ['title' => 'Draft', 'category' => ['text' => 'Display category'],
        'period_start' => '2026-10-05', 'supporting_info' => ['episodes' => [], 'medical_records' => [['name' => 'Report']]]],
        'encounterUuid' => '33333333-3333-4333-8333-333333333333', 'employeeUuid' => null],
];
