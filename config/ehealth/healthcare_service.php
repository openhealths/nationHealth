<?php

declare(strict_types=1);

// Config params Healthcare Service
// https://e-health-ua.atlassian.net/wiki/spaces/ESOZ/pages/20305346763/DRAFT+Config+params+Healthcare+Service+ENT-032
return [
    'categories' => [
        'EMERGENCY' => ['MSP'],
        'OUTPATIENT' => ['MSP', 'PHARMACY_DRUGS'],
        'PHARMACY' => ['PHARMACY', 'PHARMACY_DRUGS'],
        'PRIMARY_CARE' => ['MSP']
    ],
    'legal_entities_allowed_types' => ['PRIMARY_CARE', 'OUTPATIENT', 'EMERGENCY', 'PHARMACY'],
    'providing_condition_field_required_for_categories' => ['MSP'],
    'license_type' => [
        'PHARMACY_DRUGS' => 'PHARMACY_DRUGS',
        'PHARMACY' => 'PHARMACY'
    ],
    'speciality_type_field_required_for_categories' => ['MSP'],
    'type_field_required_for_categories' => ['PHARMACY_DRUGS']
];
