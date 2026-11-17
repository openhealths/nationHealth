<?php

declare(strict_types=1);

// Config params Encounter
// https://e-health-ua.atlassian.net/wiki/spaces/ESOZ/pages/20212351131/DRAFT+Config+params+Encounter+ENT-026
return [
    'package_allowed_observation_performer_employee_types' => [
        'DOCTOR',
        'SPECIALIST',
        'ASSISTANT',
        'LABORANT',
    ],
    'max_days_passed' => 90,
    'package_allowed_condition_asserter_employee_types' => [
        'DOCTOR',
        'SPECIALIST',
        'ASSISTANT',
        'MED_COORDINATOR',
    ],
    'package_allowed_diagnostic_report_performer_employee_types' => [
        'DOCTOR',
        'SPECIALIST',
        'ASSISTANT',
        'MED_COORDINATOR',
        'LABORANT',
    ],
    'package_allowed_encounter_participant_employee_types' => [
        'DOCTOR',
        'SPECIALIST',
        'ASSISTANT',
        'MED_COORDINATOR',
    ],
    'package_allowed_procedure_performer_employee_types' => [
        'DOCTOR',
        'SPECIALIST',
        'ASSISTANT',
        'LABORANT',
    ],
    'immunization_max_days_passed' => 54750,

    'type_encounter_participant_employee_types_allowed' => [
        'concilium' => ['SPECIALIST']
    ],

    // https://e-health-ua.atlassian.net/wiki/spaces/EH/pages/583402009/Medical+Events+Dictionaries+and+configurations#legal_entity_encounter_classes
    'legal_entity_classes' => [
        'PRIMARY_CARE' => ['PHC'],
        'MSP' => ['PHC'],
        'OUTPATIENT' => ['AMB', 'INPATIENT']
    ],
    // https://e-health-ua.atlassian.net/wiki/spaces/EH/pages/583402009/Medical+Events+Dictionaries+and+configurations#performer_employee_encounter_classes
    'performer_employee_classes' => [
        'DOCTOR' => ['PHC'],
        'SPECIALIST' => ['AMB', 'INPATIENT'],
        'ASSISTANT' => ['PHC', 'AMB', 'INPATIENT'],
        'MED_COORDINATOR' => ['AMB']
    ],
    // https://e-health-ua.atlassian.net/wiki/spaces/EH/pages/583402009/Medical+Events+Dictionaries+and+configurations#performer_employee_encounter_types
    'performer_employee_types' => [
        'SPECIALIST' => ['service_delivery_location', 'virtual', 'patient_identity', 'discharge', 'field', 'home', 'covid', 'intervention', 'concilium'],
        'DOCTOR' => ['service_delivery_location', 'virtual', 'home', 'field', 'intervention'],
        'ASSISTANT' => ['intervention'],
        'MED_COORDINATOR' => ['service_delivery_location', 'virtual']
    ],
    // https://e-health-ua.atlassian.net/wiki/spaces/EH/pages/583402009/Medical+Events+Dictionaries+and+configurations#encounter_class_encounter_types
    'class_types' => [
        'AMB' => ['service_delivery_location', 'virtual', 'patient_identity', 'field', 'home', 'intervention', 'concilium'],
        'INPATIENT' => ['patient_identity', 'discharge', 'service_delivery_location', 'intervention', 'concilium'],
        'PHC' => ['service_delivery_location', 'virtual', 'home', 'field', 'intervention']
    ],
    // https://e-health-ua.atlassian.net/wiki/spaces/ESOZ/pages/17571709115/REST+API+Submit+Encounter+Package+API-007-026-0003#Validate-observations-for-encounter.type-%3D%3D-%22patient_identity%22
    'preperson_required_observation_codes' => ['8302-2', '46098-0'],
    'preperson_allowed_observation_codes' => [
        '8302-2', '46098-0', '29463-7', 'stature', 'eye_colour', 'hair_color', 'hair_length', 'beard', 'mustache',
        'clothes', 'peculiarity'
    ]
];
