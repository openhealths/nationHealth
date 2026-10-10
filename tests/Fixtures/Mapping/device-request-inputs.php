<?php

declare(strict_types=1);

$uuids = ['employee_uuid' => 'employee-test', 'encounter_uuid' => 'encounter-test', 'episode_uuid' => 'episode-test'];
$data = ['uuid' => 'request-test', 'device_id' => '12345678-1234-1234-1234-123456789012', 'quantity' => 2.9, 'quantity_code' => 'PIECE'];

return [
    'definition_care_plan' => ['data' => $data + ['program_id' => 'program-test', 'supporting_info' => [7 => ['uuid' => 'condition-test', 'type' => 'CONDITION'], 12 => ['uuid' => 'observation-test', 'type' => 'OBSERVATION']]], 'uuids' => $uuids, 'carePlanUuid' => 'care-plan-test', 'activityUuid' => 'activity-test'],
    'classification_zero' => ['data' => array_replace($data, ['device_id' => 'CLASS-A', 'device_code_type' => 'CLASSIFICATION_TYPE', 'quantity' => 0, 'quantity_code' => 'PACK', 'priority' => 'urgent']), 'uuids' => ['employee_uuid' => 'employee-test', 'episode_uuid' => 'episode-test']],
    'explicit_classification_uuid' => ['data' => $data + ['device_code_type' => 'CLASSIFICATION_TYPE', 'supporting_info' => [['uuid' => '', 'type' => 'condition'], ['uuid' => 'missing-type']]], 'uuids' => ['employee_uuid' => 'employee-test']],
    'explicit_definition_code' => ['data' => array_replace($data, ['device_id' => 'non-uuid-definition', 'device_code_type' => 'DEVICE_DEFINITION', 'started_at' => '2026-10-06', 'ended_at' => '2026-10-05', 'program_id' => '', 'supporting_info' => []]), 'uuids' => $uuids, 'carePlanUuid' => 'care-plan-test'],
    'past_dates' => ['data' => $data + ['started_at' => '2020-01-01', 'ended_at' => '2020-02-01'], 'uuids' => $uuids],
    'timezone_dst' => ['data' => $data + ['started_at' => '2026-10-25T00:30:00+03:00', 'ended_at' => '2026-10-26T00:30:00+02:00'], 'uuids' => $uuids],
    'defaults' => ['data' => ['uuid' => 'request-test'], 'uuids' => ['employee_uuid' => 'employee-test']],
    'empty_and_null_values' => ['data' => $data + ['intent' => null, 'priority' => null, 'program_id' => '0', 'supporting_info' => [3 => ['uuid' => '0', 'type' => 'condition']]], 'uuids' => ['employee_uuid' => 'employee-test', 'encounter_uuid' => '', 'episode_uuid' => '']],
];
