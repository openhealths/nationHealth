<?php

declare(strict_types=1);

$uuids = [
    'person_uuid' => '10000000-0000-4000-8000-000000000001',
    'employee_uuid' => '10000000-0000-4000-8000-000000000002',
    'legal_entity_uuid' => '10000000-0000-4000-8000-000000000003',
    'encounter_uuid' => '10000000-0000-4000-8000-000000000004',
    'episode_uuid' => '10000000-0000-4000-8000-000000000005',
];
$data = [
    'uuid' => '20000000-0000-4000-8000-000000000001',
    'service_id' => '20000000-0000-4000-8000-000000000002',
];

return [
    'care_plan_all_fields' => [
        'data' => $data + [
            'program_id' => '30000000-0000-4000-8000-000000000001',
            'category' => 'diagnostic_procedure',
            'priority' => 'urgent',
            'quantity' => 2.0,
            'quantity_system' => 'SERVICE_UNIT',
            'quantity_code' => 'PROCEDURE',
            'started_at' => '2026-09-01',
            'ended_at' => '2026-09-02',
            'supporting_info' => '[{"uuid":"40000000-0000-4000-8000-000000000001","type":"Observation"},{"uuid":"","type":"Condition"}]',
            'reason_reference' => [7 => ['uuid' => '40000000-0000-4000-8000-000000000002', 'type' => 'Condition']],
            'patient_instruction' => 'Пройти обстеження / повторно',
            'inform_with' => '50000000-0000-4000-8000-000000000001|OTP|+380000000000',
        ],
        'uuids' => $uuids,
        'carePlanUuid' => '60000000-0000-4000-8000-000000000001',
        'activityUuid' => '60000000-0000-4000-8000-000000000002',
    ],
    'encounter_zero_quantity' => [
        'data' => $data + ['quantity' => 0.0, 'quantity_system' => '', 'quantity_code' => '', 'patient_instruction' => '0'],
        'uuids' => $uuids,
    ],
    'episode_minimal' => [
        'data' => $data,
        'uuids' => array_replace($uuids, ['encounter_uuid' => null]),
    ],
    'no_context_incomplete_based_on' => [
        'data' => $data + ['intent' => null, 'priority' => null, 'quantity' => null],
        'uuids' => array_replace($uuids, ['encounter_uuid' => null, 'episode_uuid' => null]),
        'carePlanUuid' => '60000000-0000-4000-8000-000000000001',
    ],
    'invalid_reference_rows' => [
        'data' => $data + ['supporting_info' => [['type' => 'Condition'], ['uuid' => '']], 'reason_reference' => '{broken'],
        'uuids' => $uuids,
    ],
    'only_period_end' => [
        'data' => $data + ['ended_at' => '2026-12-20', 'inform_with' => ['auth_method_id' => '50000000-0000-4000-8000-000000000001']],
        'uuids' => $uuids,
    ],
    'only_period_start' => [
        'data' => $data + ['started_at' => '2026-10-01T12:30:00+03:00', 'quantity' => 1.25],
        'uuids' => $uuids,
    ],
    'empty_optional_values' => [
        'data' => $data + ['program_id' => '', 'category' => '', 'supporting_info' => [null, 'invalid'], 'reason_reference' => [], 'inform_with' => '', 'started_at' => '', 'ended_at' => ''],
        'uuids' => $uuids,
    ],
];
