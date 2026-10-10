<?php

declare(strict_types=1);

return [
    'local medication' => ['form' => ['kind' => 'medication_request', 'quantity' => '5', 'quantity_system' => 'MEDICATION_UNIT', 'quantity_code' => 'MG', 'daily_amount' => '1.25', 'description' => 'Local', 'product_reference' => 'drug', 'product_codeable_concept' => '', 'goal' => 'goal'], 'program' => 'program', 'start' => '2026-10-05', 'end' => '2026-10-25', 'grounds' => [['type' => 'Observation', 'uuid' => 'observation']]],
    'local empty values' => ['form' => ['kind' => 'device_request', 'quantity' => '0', 'quantity_code' => '', 'description' => '0', 'goal' => '0', 'product_codeable_concept' => 'classification'], 'program' => null, 'start' => null, 'end' => null, 'grounds' => []],
    'remote scalar and sparse' => ['remote' => ['status' => 'scheduled', 'detail' => ['kind' => 'device_request', 'program' => 'program', 'product_codeable_concept' => 'classification']]],
    'remote aliases and zero' => ['remote' => ['status' => 'scheduled', 'detail' => [
        'kind' => ['coding' => [['code' => 'medication_request']]], 'quantity' => ['value' => 0, 'system' => 'MEDICATION_UNIT', 'code' => 'MG'],
        'dailyAmount' => ['value' => '1.25', 'code' => 'MG'], 'daily_amount' => ['value' => '99', 'code' => 'ignored'],
        'description' => 'Remote', 'scheduledPeriod' => ['start' => '2026-10-05T12:00:00Z'], 'scheduled_period' => ['start' => '2026-10-01T12:00:00Z', 'end' => '2026-10-25T12:00:00Z'],
        'productReference' => ['identifier' => ['value' => 'drug']], 'reasonCode' => [['coding' => [['code' => 'A']]]],
        'program' => ['identifier' => ['value' => 'program']], 'status_reason' => ['text' => 'completed'],
        'remaining_quantity' => ['value' => 0, 'unit' => 'MG'], 'remainingQuantity' => ['value' => 9, 'system' => 'MEDICATION_UNIT', 'code' => 'ignored'],
        'reasonReference' => [
            ['identifier' => ['value' => 'existing/path']],
            ['identifier' => ['value' => 'report', 'type' => ['coding' => [['code' => 'DIAGNOSTIC_REPORT']]]]],
            ['identifier' => ['value' => 'observation', 'type' => ['coding' => [['code' => 'observation']]]]],
            ['identifier' => ['value' => 'unknown', 'type' => ['coding' => [['code' => 'unknown']]]]],
            ['identifier' => ['value' => '0']],
        ],
        'goal' => [['coding' => [['code' => 'goal']]], ['identifier' => ['value' => 'reference']], ['coding' => [['code' => '0']]]],
        'outcomeReference' => [['identifier' => ['value' => 'outcome']], ['identifier' => ['value' => '0']]],
        'outcomeCodeableConcept' => ['coding' => [['code' => 'outcome-code']]],
    ]]],
    'remote explicit nulls' => ['remote' => ['status' => 'new', 'detail' => ['kind' => null, 'goal' => [], 'reason_reference' => [], 'description' => null, 'quantity' => null, 'daily_amount' => null, 'program' => null]]],
];
