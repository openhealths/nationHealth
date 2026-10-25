<?php

declare(strict_types=1);

$reference = static fn (mixed $value, mixed $type): array => ['identifier' => ['value' => $value, 'type' => ['coding' => [['code' => $type]]]]];

return [
    'full_camel_response' => [
        'uuid' => 'local-uuid', 'id' => 'remote-id', 'status' => 'completed',
        'requisition' => 'primary', 'requestNumber' => 'secondary', 'request_number' => 'third',
        'occurrencePeriod' => ['start' => '2026-10-01T14:15:00+03:00', 'end' => '2026-11-01T09:30:00Z'],
        'started_at' => '1999-01-01', 'ended_at' => '1999-02-01',
        'code' => ['identifier' => ['value' => 'service-primary'], 'coding' => [['code' => 'service-secondary']]],
        'service' => ['id' => 'service-third'], 'quantity' => ['value' => 0], 'quantityInteger' => 4,
        'program' => $reference('program-id', 'medical_program'), 'intent' => 'proposal',
        'category' => [['coding' => [['code' => 'diagnostic_procedure']]]],
        'basedOn' => [$reference('activity-id', 'care_plan_activity')], 'context' => $reference('encounter-id', 'encounter'),
        'priority' => 'urgent', 'note' => [['text' => 'first note'], ['text' => 'second note']],
        'patientInstruction' => 'camel instruction', 'patient_instruction' => 'snake instruction',
        'informWith' => ['name' => 'primary'], 'inform_with' => 'secondary',
        'reasonReference' => [$reference('condition-id', 'condition')],
        'supportingInfo' => [$reference('observation-id', 'observation')],
        'employee_id' => 999, 'person_id' => 999, 'unknown' => ['retained elsewhere'],
    ],
    'flat_fallbacks' => [
        'id' => 'remote-id', 'request_number' => 'snake-number', 'started_at' => '2026-10-02', 'ended_at' => '2026-10-03',
        'service' => ['id' => 'service-id'], 'quantityInteger' => 0, 'patient_instruction' => 'snake instruction',
        'inform_with' => 'phone', 'reason_reference' => [$reference('condition-id', 'condition')],
        'supporting_info' => [$reference('ignored', 'observation')], 'based_on_uuid' => 'ignored', 'context_uuid' => 'ignored',
    ],
    'null_primary_falls_back' => [
        'uuid' => null, 'id' => 'remote-id', 'requisition' => null, 'requestNumber' => 'camel-number',
        'request_number' => 'snake-number', 'occurrencePeriod' => ['start' => null], 'started_at' => '2026-10-02',
        'code' => ['identifier' => ['value' => null], 'coding' => [['code' => 'coded-service']]],
        'quantity' => ['value' => null], 'quantityInteger' => 2, 'patientInstruction' => null,
        'patient_instruction' => 'fallback', 'informWith' => null, 'inform_with' => [],
        'reasonReference' => null, 'reason_reference' => [$reference('reason-id', 'condition')],
    ],
    'empty_primary_does_not_fall_back' => [
        'uuid' => '', 'id' => 'ignored', 'requisition' => '', 'requestNumber' => 'ignored',
        'occurrencePeriod' => ['start' => '', 'end' => ''], 'started_at' => 'ignored', 'ended_at' => 'ignored',
        'code' => ['identifier' => ['value' => ''], 'coding' => [['code' => 'ignored']]],
        'quantity' => ['value' => '0'], 'quantityInteger' => 5, 'patientInstruction' => '', 'patient_instruction' => 'ignored',
        'informWith' => [], 'inform_with' => 'ignored', 'reasonReference' => [],
        'reason_reference' => [$reference('ignored', 'condition')],
    ],
    'incomplete_reference_rows' => [
        'reasonReference' => [$reference('only-id', null), $reference(null, 'condition'), [], 'ignored scalar', null],
        'supportingInfo' => [$reference('only-id', null), $reference(null, 'observation'), [], 'scalar', null],
    ],
    'reference_aliases_do_not_override_identifier' => [
        'reasonReference' => [['uuid' => 'wrong', 'type' => 'wrong', ...$reference('right', 'condition')], ['uuid' => 'ignored', 'type' => 'ignored']],
        'supportingInfo' => [['uuid' => 'wrong', 'type' => 'wrong', ...$reference('right', 'observation')]],
    ],
    'object_category_and_plain_note_are_not_imported' => [
        'category' => ['coding' => [['code' => 'ignored']]], 'note' => 'ignored plain note',
        'reasonReference' => 'invalid list', 'supportingInfo' => [],
    ],
    'secondary_number_and_fractional_quantity' => ['requestNumber' => 'camel-number', 'quantityInteger' => 0.5],
    'false_notification_and_empty_note' => ['informWith' => false, 'note' => [['text' => '']]],
    'absent_fields' => [],
];
