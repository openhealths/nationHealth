<?php

declare(strict_types=1);

$base = ['kind' => 'service_request', 'status' => 'draft', 'quantity' => 3, 'quantity_system' => 'SERVICE_UNIT', 'quantity_code' => 'piece', 'scheduled_period_start' => '2026-10-01', 'scheduled_period_end' => '2026-10-20'];
$device = array_replace($base, ['kind' => 'device_request', 'product_reference' => '0b70715d-0e6e-4a89-889f-815cf429cb87', 'product_codeable_concept' => '18_09_03', 'quantity_system' => 'device_unit', 'program' => 'program']);

return [
    'service references' => ['model' => $base + ['product_reference' => 'service', 'description' => 'Test', 'do_not_perform' => true, 'reason_code' => 'A', 'reason_reference' => ['Condition/condition', 'DiagnosticReport/report', 'bare-uuid'], 'goal' => ['goal-a', 'goal-b']]],
    'medication amount' => ['model' => array_replace($base, ['kind' => 'medication_request', 'quantity' => 3, 'daily_amount' => '1.25', 'quantity_system' => 'MEDICATION_UNIT', 'quantity_code' => 'ML', 'daily_amount_system' => 'MEDICATION_UNIT', 'daily_amount_code' => 'MG', 'program' => 'program', 'product_reference' => 'drug'])],
    'registered periods and quantities' => ['model' => $base + ['uuid' => 'remote-activity'], 'period' => ['start' => '2026-10-03 10:15:00', 'end' => '2026-10-20 15:00:00'], 'quantity' => ['value' => '1.25', 'system' => 'SERVICE_UNIT', 'code' => 'piece', 'unit' => 'units']],
    'device uuid priority' => ['model' => $device],
    'classification only program' => ['model' => $device, 'allowed' => ['CLASSIFICATION_TYPE']],
    'definition only program' => ['model' => $device, 'allowed' => ['DEVICE_DEFINITION']],
    'classification fallback' => ['model' => array_replace($device, ['product_reference' => 'not-a-uuid', 'program' => null])],
    'empty values' => ['model' => ['kind' => 'service_request', 'status' => 'draft', 'quantity' => 0, 'daily_amount' => '0', 'description' => '0', 'do_not_perform' => false, 'reason_reference' => [], 'goal' => []]],
    'future plan clipping' => ['model' => $base, 'planStart' => '2026-10-10'],
    'non draft midday' => ['model' => array_replace($base, ['status' => 'scheduled'])],
];
