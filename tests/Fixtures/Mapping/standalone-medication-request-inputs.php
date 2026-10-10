<?php

declare(strict_types=1);

return [
    'normal' => ['patientId' => 'person-id', 'medicalProgram' => 'program-id', 'dosageInstruction' => 'Take 1 pill', 'duration' => '30'],
    'fractional_duration' => ['patientId' => 'person-id', 'medicalProgram' => 'program-id', 'dosageInstruction' => 'Приймати після їжі', 'duration' => '3.9'],
    'scientific_duration' => ['patientId' => 'person-id', 'medicalProgram' => 'program-id', 'dosageInstruction' => "Line 1\nLine 2", 'duration' => '1e2'],
    'leading_zero_duration' => ['patientId' => 'person-id', 'medicalProgram' => 'program-id', 'dosageInstruction' => 'Keep / and "quotes"', 'duration' => '003'],
];
