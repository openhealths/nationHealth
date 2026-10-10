<?php

declare(strict_types=1);

// Synthetic cases captured against the pre-refactor lifecycle on main b2239108.
return [
    'empty' => [],
    'snake' => [
        'id' => 'remote-id', 'uuid' => 'ignored-id', 'status' => 'active', 'request_number' => 'primary', 'requisition' => 'fallback',
        'occurrence_period' => ['start' => '2026-09-30T12:00:00Z', 'end' => '2026-10-30'],
        'code' => ['identifier' => ['value' => 'service-id']], 'quantity' => ['value' => 0.0],
        'program' => ['identifier' => ['value' => 'program-id']], 'intent' => 'order', 'priority' => 'routine',
        'category' => ['coding' => [['code' => 'procedure']]], 'note' => 'note', 'patient_instruction' => 'instruction',
        'inform_with' => ['auth_method_id' => 'auth-id'],
        'supporting_info' => [['identifier' => ['value' => 'condition-id', 'type' => ['coding' => [['code' => 'condition']]]]]],
        'reason_reference' => [['identifier' => ['value' => 'reason-id', 'type' => ['coding' => [['code' => 'observation']]]]]],
    ],
    'camel' => [
        'uuid' => 'legacy-id', 'requestNumber' => 'legacy-number', 'quantityInteger' => 2,
        'occurrencePeriod' => ['start' => '2026-09-30', 'end' => '2026-10-01'],
        'code' => ['coding' => [['code' => 'legacy-service']]],
        'category' => [['coding' => [['code' => 'laboratory']]]], 'note' => [['text' => 'legacy-note']],
        'patientInstruction' => 'camel wins', 'patient_instruction' => 'snake loses',
        'informWith' => 'auth-id', 'supportingInfo' => [['identifier' => ['value' => 'condition-id', 'type' => ['coding' => [['code' => 'condition']]]]]],
        'reasonReference' => [['identifier' => ['value' => 'reason-id', 'type' => ['coding' => [['code' => 'condition']]]]]],
    ],
    'partial' => ['id' => 'remote-id', 'status' => 'active', 'service' => ['id' => 'search-service']],
    'empty_values' => ['id' => '', 'status' => null, 'request_number' => '', 'quantity' => ['value' => 0], 'priority' => '', 'note' => '', 'inform_with' => [], 'supporting_info' => [], 'reason_reference' => []],
    'malformed_references' => ['supporting_info' => [null, 'bad', [], ['identifier' => ['value' => 'missing-type']]], 'reason_reference' => [null, 'bad', [], ['identifier' => ['value' => 'missing-type']]]],
    'aliases_precedence' => ['id' => null, 'uuid' => 'fallback-id', 'request_number' => null, 'requisition' => 'req', 'requestNumber' => 'other', 'occurrence_period' => ['start' => null], 'occurrencePeriod' => ['start' => '2026-10-01'], 'started_at' => '2026-09-01', 'supporting_info' => [], 'supportingInfo' => [['identifier' => ['value' => 'ignored']]], 'reasonReference' => [], 'reason_reference' => [['identifier' => ['value' => 'ignored']]]],
    'flat_dates' => ['started_at' => '30.09.2026', 'ended_at' => '01.10.2026'],
];
