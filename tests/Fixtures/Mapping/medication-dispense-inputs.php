<?php

declare(strict_types=1);

return [
    'plain' => [
        'request' => ['id' => 'prescription', 'medication_id' => 'drug'],
        'quantity' => '2.5', 'code' => ' 1234 ', 'participant' => null,
    ],
    'coded aliases' => [
        'request' => ['uuid' => 'prescription', 'medication' => ['identifier' => ['value' => 'drug']], 'medical_program' => ['identifier' => ['value' => 'program']]],
        'quantity' => '2', 'code' => '1234', 'participant' => [],
    ],
    'qualified minimum' => [
        'request' => ['id' => 'prescription', 'medication_info' => ['medication_id' => 'inn'], 'medical_program_id' => 'program'],
        'quantity' => '1.5', 'code' => '1234', 'participant' => ['medication_id' => 'brand', 'package_min_qty' => 5],
    ],
    'already above minimum' => [
        'request' => ['id' => 'prescription', 'dispense_request' => ['medication_info' => ['id' => 'drug']], 'program' => ['id' => 'program']],
        'quantity' => '7', 'code' => '', 'participant' => ['package_min_qty' => 2],
    ],
    'zero is preserved by wire contract' => [
        'request' => ['id' => 'prescription'],
        'quantity' => '0', 'code' => '', 'participant' => null,
    ],
];
