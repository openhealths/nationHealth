<?php

declare(strict_types=1);

// Config params Episode of Care
// https://e-health-ua.atlassian.net/wiki/spaces/ESOZ/pages/20217659393/DRAFT+Config+params+Episode+of+Care+ENT-027
return [
    'allowed_episode_care_manager_employee_types' => ['DOCTOR', 'SPECIALIST', 'ASSISTANT', 'MED_COORDINATOR'],
    'allow_other_le_employees_to_manage_episode' => env('EHEALTH_ALLOW_OTHER_LE_EMPLOYEES_TO_MANAGE_EPISODE', false),
    'employee_episode_types' => [
        'SPECIALIST' => ['TREATMENT', 'PREVENTION', 'PALLIATIVE_CARE', 'DG', 'REHAB', 'CONDITIONING'],
        'DOCTOR' => ['TREATMENT', 'PREVENTION', 'PALLIATIVE_CARE', 'PHC'],
        'ASSISTANT' => ['PREVENTION'],
        'MED_COORDINATOR' => ['TREATMENT', 'DG']
    ],
    'type_encounter_classes' => [
        'TREATMENT' => ['AMB', 'INPATIENT', 'PHC'],
        'PREVENTION' => ['AMB', 'INPATIENT', 'PHC'],
        'DG' => ['AMB', 'INPATIENT'],
        'REHAB' => ['AMB', 'INPATIENT'],
        'PALLIATIVE_CARE' => ['AMB', 'INPATIENT', 'PHC'],
        'PHC' => ['PHC'],
        'CONDITIONING' => ['INPATIENT']
    ],
    'legal_entity_episode_types' => [
        'OUTPATIENT' => ['TREATMENT', 'PREVENTION', 'PALLIATIVE_CARE', 'DG', 'REHAB', 'CONDITIONING'],
        'PRIMARY_CARE' => ['TREATMENT', 'PHC', 'PREVENTION', 'PALLIATIVE_CARE'],
        'MSP' => ['TREATMENT', 'PHC', 'PREVENTION', 'PALLIATIVE_CARE'],
        'MSP_PHARMACY' => ['TREATMENT', 'PHC', 'PREVENTION', 'PALLIATIVE_CARE']
    ]
];
