<?php

declare(strict_types=1);

use App\Models\LegalEntity;

// Employee role mapping: https://e-health-ua.atlassian.net/wiki/spaces/ESOZ/pages/583402994
// Shared by the three medical roles; active choices are intersected with the API dictionary.
$specialistSpecialityTypes = [
    'PHYSICAL_THERAPEUTICS', 'ERGOTHERAPEUTICS', 'IMMUNOLOGY', 'INFECTIOUS_DISEASES',
    'CARDIOLOGY', 'CLINICAL_BIOCHEMISTRY', 'CLINICAL_IMMUNOLOGY', 'CLINICAL_DIAGNOSTIC',
    'COMBUSTIOLOGY', 'COMMUNAL_HYGIENE', 'LABORATORY_IMMUNOLOGY', 'LABORATORY_RESEARCH_OF_ENVIRONMENTAL_FACTORS',
    'LABORATORY_RESEARCH_OF_ENVIRONMENT_PHYSICAL_FACTORS', 'LABORATORY_RESEARCH_OF_ENVIRONMENT_CHEMICAL_FACTORS',
    'PHYSICAL_THERAPY', 'PHYSICAL_THERAPY_AND_SPORTS_MEDICINE', 'EMERGENCY_MEDICINE', 'MEDICAL_PSYCHOLOGY',
    'MICROBIOLOGY', 'NARCOLOGY', 'TRADITIONAL_ALTERNATIVE_MEDICINE', 'NEUROLOGY', 'NEUROSURGERY', 'NEONATOLOGY',
    'NEPHROLOGY', 'GYNECOLOGIC_ONCOLOGY', 'ONCOLOGY', 'ONCOTOLARYNGOLOGY',
    'SURGICAL_ONCOLOGY', 'PUBLIC_HEALTH_ORGANIZATION', 'ORTHODONTOLOGY', 'ORTHOPEDIC_DENTISTRY',
    'ORTHOPAEDICS', 'OTORHINOLARYNGOLOGY', 'OPHTHALMOLOGY', 'PARASITOLOGY',
    'PATHOLOGIC_ANATOMY', 'PEDIATRICIAN', 'ADOLESCENT_MEDICINE', 'PROCTOLOGY',
    'RADIATION_THERAPY', 'OCCUPATIONAL_PATHOLOGY', 'PSYCHIATRY', 'PSYCHOTHERAPY',
    'PSYCHOPHYSIOLOGY', 'PULMONOLOGY', 'RADIATION_HYGIENE', 'RADIOLOGY',
    'RADIOLOGIC_DIAGNOSIS', 'RHEUMATOLOGY', 'X_RAY_RADIOLOGY', 'REFLEXOLOGY',
    'SANOLOGY', 'SEXOPATHOLOGY', 'SPORTS_MEDICINE', 'STOMATOLOGY',
    'VASCULAR_SURGERY', 'FORENSIC_MEDICINE', 'FORENSIC_MEDICAL_HISTOLOGY', 'FORENSIC_MEDICAL_EXAMINATION',
    'FORENSIC_IMMUNOLOGY', 'FORENSIC_CRIMINOLOGY', 'FORENSIC_MEDICAL_TOXICOLOGY', 'FORENSIC_CYTOLOGY',
    'FORENSIC_PSYCHIATRIC_EXAMINATION', 'AUDIOLOGY', 'THERAPEUTIC_DENTISTRY', 'THERAPIST',
    'TOXICOLOGY', 'THORACIC_SURGERY', 'TRANSPLANTOLOGY', 'TRANSFUSIOLOGY',
    'ULTRASONIC_DIAGNOSIS', 'UROLOGY', 'PHYSIOTHERAPY', 'PHYSICAL_MEDICINE_AND_REHABILITATION',
    'PHTHISIOLOGY', 'FUNCTIONAL_DIAGNOSTICS', 'SURGICAL_DENTISTRY', 'GENERAL_SURGERY',
    'CARDIOVASCULAR_SURGERY', 'AEROSPACE_MEDICINE', 'OBSTETRICS_AND_GYNECOLOGY', 'ALLERGOLOGY',
    'ANAESTHETICS', 'BACTERIOLOGY', 'VIROLOGY', 'GASTROENTEROLOGY',
    'GENERAL_HEMATOLOGY', 'LABORATORY_GENETICS', 'MEDICAL_GENETICS', 'GERIATRICS',
    'PEDIATRIC_HYGIENE', 'OCCUPATIONAL_MEDICINE', 'FOOD_HYGIENE', 'DISINFECTION_',
    'DERMATO-VENEREOLOGY', 'PEDIATRIC_ALLERGY', 'PEDIATRIC_ANAESTHETICS', 'PEDIATRIC_GASTROENTEROLOGY',
    'PEDIATRIC_HEMATOLOGY', 'PEDIATRIC_GYNECOLOGY', 'PEDIATRIC_DERMATO-VENEREOLOGY', 'PEDIATRIC_ENDOCRINOLOGY',
    'PEDIATRIC_IMMUNOLOGY', 'PEDIATRIC_CARDIOLOGY', 'PEDIATRIC_NEUROLOGY', 'PEDIATRIC_NEPHROLOGY',
    'PEDIATRIC_ONCOLOGY', 'PEDIATRIC_ORTHOPAEDICS', 'PEDIATRIC_OTOLARYNGOLOGY', 'PEDIATRIC_OPHTHALMOLOGY',
    'PEDIATRIC_PATHOLOGY', 'PEDIATRIC_PSYCHIATRY', 'PEDIATRIC_PULMONOLOGY', 'PEDIATRIC_STOMATOLOGY',
    'PEDIATRIC_UROLOGY', 'PEDIATRIC_PHTHISIOLOGY', 'PEDIATRIC_SURGERY', 'PEDIATRIC_INFECTIOUS_DISEASE',
    'DIETETICS', 'ENDOCRINOLOGY', 'ENDOSCOPY', 'EPIDEMIOLOGY',
    'COMMON_HYGIENE', 'PEDIATRIC_HEMATOLOGY_AND_ONCOLOGY', 'INVASIVE_ELECTROPHYSIOLOGY', 'INTERVENTIONAL_CARDIOLOGY',
    'PEDIATRIC_NEUROLOGICAL_SURGERY', 'PERIODONTOLOGY', 'PLASTIC_SURGERY', 'ORAL_AND_MAXILLOFACIAL_SURGERY',
    'CHILD_CARDIOLOGY', 'PEDIATRIC_RHEUMATOLOGY', 'SURGICAL_DERMATOLOGY', 'INTERNAL_DISEASES',
    'CLINICAL_ONCOLOGY', 'RADIATION_ONCOLOGY', 'NUCLEAR_MEDICINE', 'TRANSPLANT_COORDINATION',
];

return [
    'api' => [
        'domain' => env('EHEALTH_API_URL', 'private-anon-cb2ce4f7fc-uaehealthapi.apiary-mock.com'),
        'token' => env('EHEALTH_X_CUSTOM_PSK', 'X-Custom-PSK'),
        'api_key' => env('EHEALTH_API_KEY', ''),
        'mis_api_key' => env('EHEALTH_MIS_API_KEY', ''),
        'mis_token' => env('EHEALTH_MIS_TOKEN'),
        'mis_id' => env('EHEALTH_MIS_ID'),
        'mis_edrpou' => env('EHEALTH_MIS_EDRPOU'),
        'callback_prod' => env('EHEALTH_CALLBACK_PROD', true),
        'auth_host' => env('EHEALTH_AUTH_HOST', 'https://auth-preprod.ehealth.gov.ua'),
        'redirect_uri' => env('EHEALTH_OAUTH_REDIRECT_URI', 'https://openhealths.com/ehealth/oauth'),
        'connection_redirect_uri' => env('EHEALTH_REDIRECT_URI', 'https://openhealths.com'),
        'url_dev' => env('EHEALTH_URL_DEV', 'http://localhost'),
        'auth_ehealth' => env('EHEALTH_CODE_TOKEN', 'user_id_auth_ehealth'),
        'oauth' => [
            'bearer_token' => env('EHEALTH_OAUTH_TOKEN', 'auth_token'),
            'token_scopes' => env('EHEALTH_OAUTH_TOKEN_SCOPES', 'auth_token_scopes'),
            'tokens' => env('EHEALTH_OAUTH_TOKENS', '/oauth/tokens'),
            'user' => env('EHEALTH_OAUTH_USER', '/oauth/user'),
            'logout' => env('EHEALTH_OAUTH_LOGOUT', '/auth/logout')
        ],
        'timeout' => 10,
        'queueTimeout' => 60,
        'cooldown' => 300,
        'retries' => 10,
        'page_size' => env('EHEALTH_PAGE_SIZE', 300),
        'page_size_max' => env('EHEALTH_PAGE_SIZE_MAX', 500),
        'page_size_le_connections_max' => env('EHEALTH_MIS_CONNECTIONS_PAGE_SIZE_MAX', 100),
        // Install seeds icd_10 from the live dictionary. CI has no PSK; the public mock returns 403.
        'seed_icd10_from_api' => filter_var(env('EHEALTH_SEED_ICD10', true), FILTER_VALIDATE_BOOL),
    ],

    'party_verification' => [
        // Local parties synced synchronously via getDetails before the rest go to the queue.
        'details_sync_page_size' => (int) env('EHEALTH_PARTY_VERIFICATION_DETAILS_PAGE_SIZE', 50),
    ],

    'auth' => [
        'delay_seconds' => 900,     // Window in seconds within which failed login attempts are counted
        'max_login_attempts' => 5,  // Amount of the wrong attempt before locking out
        'lockout_seconds' => 300    // Amount of the seconds the account stays locked out
    ],

    'legal_entity_localized_names' => [
            LegalEntity::TYPE_EMERGENCY => 'legal-entity.types.emergency',
            LegalEntity::TYPE_MIS => 'legal-entity.types.mis',
            LegalEntity::TYPE_MSP => 'legal-entity.types.msp',
            LegalEntity::TYPE_MSP_PHARMACY => 'legal-entity.types.msp_pharmacy',
            LegalEntity::TYPE_NHS => 'legal-entity.types.nhs',
            LegalEntity::TYPE_OUTPATIENT => 'legal-entity.types.outpatient',
            LegalEntity::TYPE_PHARMACY => 'legal-entity.types.pharmacy',
            LegalEntity::TYPE_PRIMARY_CARE => 'legal-entity.types.primary_care',
            LegalEntity::TYPE_MSP_LIMITED => 'legal-entity.types.msp_limited'
    ],

    'legal_entity_types' => include config_path('scopes/legal_entity_types.php'),

    // https://e-health-ua.atlassian.net/wiki/spaces/EH/pages/583402982/Legal_Entity_Type+vs+Employee_Type+validation+rules
    'legal_entity_employee_types' => [
        'MSP' => [
            'OWNER', 'HR', 'DOCTOR', 'ADMIN', 'RECEPTIONIST', 'LABORANT'
        ],
        'MSP_LIMITED' => [
            'REORGANIZATION_OWNER', 'OWNER', 'ADMIN', 'DOCTOR'
        ],
        'PRIMARY_CARE' => [
            'REORGANIZATION_OWNER', 'OWNER', 'HR', 'DOCTOR', 'ASSISTANT', 'ADMIN', 'RECEPTIONIST', 'MED_ADMIN', 'LABORANT'
        ],
        // 'MSP_PHARMACY' => [
        //     'OWNER', 'HR', 'DOCTOR', 'ADMIN', 'PHARMACIST', 'RECEPTIONIST'
        // ],
        'PHARMACY' => [
            'PHARMACY_OWNER', 'OWNER', 'PHARMACIST', 'HR'
        ],
        'OUTPATIENT' => [
            'REORGANIZATION_OWNER', 'OWNER', 'HR', 'ASSISTANT', 'SPECIALIST', 'ADMIN', 'RECEPTIONIST', 'MED_ADMIN', 'LABORANT', 'MED_COORDINATOR'
        ],
        'EMERGENCY' => [
            'REORGANIZATION_OWNER', 'OWNER', 'HR', 'SPECIALIST', 'ASSISTANT', 'ADMIN'
        ],
    ],

    'capitation_contract_max_period_days' => 366,

    /*
     * Employee types that may enter position as free text instead of POSITION dictionary codes.
     * Matches ESOZ EMPLOYEE_TYPE_CUSTOM_POSITION_ALLOWED.
     */
    'employee_type_custom_position_allowed' => ['ADMIN', 'HR', 'RECEPTIONIST'],

    'reimbursement_contract_max_period_day' => env('EHEALTH_REIMBURSEMENT_CONTRACT_MAX_PERIOD_DAY', 1096),

    // https://e-health-ua.atlassian.net/wiki/spaces/EH/pages/583761951/Rate+Limits
    'rate_limit' => [
        'approval' => 5,
        'employee_request' => 19,
        'employee' => 29,
        'division_request' => 50,
        'healthcare_service' => 50,
        'equipment' => 50,
        'episode' => 50,
        'encounter' => 50,
        'clinical_impression' => 50,
        'immunization' => 50,
        'observation' => 50,
        'condition' => 50,
        'diagnostic_report' => 50,
        'device' => 50,
        'specimen' => 50,
        'detected_issue' => 50,
        'device_association' => 50,
        'device_dispense' => 50,
        'employee_role' => 50,
        'party_request' => 30,
        'declaration' => [
            'minute' => 9,
            'hour' => 99
        ],
        'declaration_request' => [
            'minute' => 19,
            'hour' => 149
        ],
        'legal_entity_legators' => 2,
        'legal_entity_by_id' => 200,
        'person_authentication_method' => 20,
        'remote_job' => 1399
    ],

    /*
    |--------------------------------------------------------------------------
    | Asynchronous job polling
    |--------------------------------------------------------------------------
    |
    | eHealth answers write requests with a job link that has to be polled until
    | it reaches a final state. Polling blocks the request, so max_attempts *
    | interval_seconds is the worst-case time a user waits before the operation
    | is reported as unresolved.
    |
    */
    'jobs' => [
        'max_attempts' => env('EHEALTH_JOB_MAX_ATTEMPTS', 15),
        'interval_seconds' => env('EHEALTH_JOB_INTERVAL_SECONDS', 2),
    ],

    'employee_type' => [
        'OWNER' => [
            'position' => [
                'P1', 'P2', 'P3', 'P32', 'P4', 'P5', 'P6', 'P18', 'P19', 'P22', 'P23', 'P24', 'P25', 'P26', 'P229',
                'P230', 'P231', 'P232', 'P233', 'P234', 'P235', 'P236', 'P237', 'P238', 'P239', 'P247', 'P249', 'P257'
            ]
        ],
        'PHARMACY_OWNER' => [
            'position' => ['P1', 'P2', 'P3', 'P4', 'P5', 'P6', 'P18', 'P19', 'P22', 'P23', 'P24', 'P25', 'P26', 'P32',
                           'P229', 'P230', 'P231', 'P232', 'P233', 'P234', 'P235', 'P236', 'P237', 'P238', 'P239',
                           'P240', 'P247', 'P249', 'P257'],
        ],
        'PHARMACIST' => [
            'position' => ['P16', 'P19', 'P20', 'P21', 'P217', 'P218', 'P219', 'P220', 'P221', 'P222', 'P223', 'P259',
                           'P260', 'P261', 'P262', 'P263', 'P264', 'P265'],
            'speciality_type' => ['PHARMACEUTICS_ORGANIZATION', 'PROVISOR', 'ANALYTICAL_AND_CONTROL_PHARMACY',
                                  'CLINICAL_PROVISOR', 'PHARMACIST', 'PHARMACEUTICAL_COSMETOLOGY', 'NURSING_PRACTICE'],
            'education_degree' => ['EXPERT', 'MASTER', 'BACHELOR', 'JUNIOR_EXPERT', 'JUNIOR_BACHELOR'],
            'qualification_type' => ['REATTESTATION', 'SPECIALIZATION', 'STAZHUVANNYA', 'POSTGRADUATE'],
            'speciality_level' => ['FIRST', 'SECOND', 'HIGHEST', 'NOT_APPLICABLE'],
            'speciality_qualification_type' => ['AWARDING', 'DEFENSE'],
        ],
        'ADMIN' => [
            'position' => [
                'P5', 'P6', 'P14', 'P18', 'P19'
            ]
        ],
        'HR' => [
            'position' => ['P14']
        ],
        'ASSISTANT' => [
            'position' => ['P17', 'P66', 'P169', 'P170', 'P171', 'P173', 'P174', 'P175', 'P176', 'P177', 'P178', 'P179',
                           'P180', 'P181', 'P182', 'P183', 'P184', 'P185', 'P186', 'P187', 'P188', 'P189', 'P190',
                           'P191', 'P192', 'P193', 'P194', 'P195', 'P196', 'P197', 'P198', 'P199', 'P200', 'P201',
                           'P202', 'P203', 'P204', 'P205', 'P206', 'P207', 'P208', 'P209', 'P210', 'P211', 'P212',
                           'P213', 'P214', 'P215', 'P216', 'P250', 'P251', 'P252', 'P253', 'P256', 'P284', 'P285', 'P286'],
            'speciality_type' => ['ORTHOPEDIC_DENTISTRY', 'X_RAY_RADIOLOGY', 'SANOLOGY', 'STOMATOLOGY',
                                  'GENERAL_MEDICINE', 'MEDICAL_CASE_EMERGENCY_MEDICINE',
                                  'PUBLIC_HEALTH_AND_PREVENTIVE_MEDICINE', 'CLINICAL_LABORATORY', 'HYGIENE_LABORATORY',
                                  'PATHOLOGY_LABORATORY', 'OBSTETRICS', 'NURSING', 'OPERATING_NURSING',
                                  'MEDICAL_STATISTICS', 'PHYSICAL_THERAPEUTICS', 'ERGOTHERAPEUTICS', 'PSYCHOLOGY',
                                  'SPECIAL_EDUCATION', 'PHILOLOGY', 'THERAPY_OF_SPEECH_AND_LANGUAGE', 'PSYCHOTHERAPY',
                                  'CLINICAL_PSYCHOLOGY', 'ANESTHESIOLOGY_AND_RESUSCITATION', 'OPTOMETRY',
                                  'PROSTHETICS_AND_ORTHOTICS', 'TRAUMATOLOGY_AND_ORTHOPEDICS',
                                  'DENTISTRY_ORTHOPEDIC', 'LABORATORY_DIAGNOSTICS', 'PREVENTIVE_DENTISTRY'],
            'education_degree' => ['EXPERT', 'MASTER', 'BACHELOR', 'JUNIOR_EXPERT', 'JUNIOR_BACHELOR'],
            'qualification_type' => ['CLINICAL_RESIDENCY', 'INTERNSHIP', 'REATTESTATION', 'SPECIALIZATION',
                                     'STAZHUVANNYA', 'POSTGRADUATE', 'TOPIC_IMPROVEMENT'],
            'speciality_level' => ['FIRST', 'SECOND', 'HIGHEST', 'BASIC', 'NOT_APPLICABLE'],
            'speciality_qualification_type' => ['AWARDING', 'DEFENSE'],
        ],
        'DOCTOR' => [
            'position' => ['P7', 'P8', 'P9', 'P10', 'P11'],
            'speciality_type' => ['FAMILY_DOCTOR', 'PEDIATRICIAN', 'THERAPIST', 'INTERNAL_DISEASES'],
            'education_degree' => ['EXPERT', 'MASTER', 'BACHELOR', 'JUNIOR_EXPERT'],
            'qualification_type' => ['CLINICAL_RESIDENCY', 'INTERNSHIP', 'REATTESTATION', 'SPECIALIZATION',
                                     'STAZHUVANNYA', 'POSTGRADUATE', 'TOPIC_IMPROVEMENT'],
            'speciality_level' => ['FIRST', 'SECOND', 'HIGHEST', 'BASIC', 'NOT_APPLICABLE'],
            'speciality_qualification_type' => ['AWARDING', 'DEFENSE'],
        ],
        'LABORANT' => [
            'position' => ['P17', 'P170', 'P173', 'P241', 'P242', 'P243', 'P244', 'P251', 'P256', 'P271', 'P272',
                           'P273', 'P274', 'P276', 'P277', 'P278', 'P279', 'P281'],
            'speciality_type' => ['VIROLOGY', 'MICROBIOLOGY', 'LABORATORY_GENETICS', 'LABORATORY_IMMUNOLOGY',
                                  'CLINICAL_DIAGNOSTIC', 'PARASITOLOGY', 'BACTERIOLOGY', 'CLINICAL_BIOCHEMISTRY',
                                  'CLINICAL_LABORATORY', 'HYGIENE_LABORATORY', 'PATHOLOGY_LABORATORY',
                                  'GENERAL_MEDICINE', 'PUBLIC_HEALTH_AND_PREVENTIVE_MEDICINE', 'CYTOMORPHOLOGY',
                                  'CLINICAL_DIAGNOSTICS', 'LABORATORY_DIAGNOSTICS_VIROLOGY_MICROBIOLOGY',
                                  'LABORATORY_DIAGNOSTICS'],
            'education_degree' => ['MASTER', 'EXPERT', 'BACHELOR', 'JUNIOR_EXPERT'],
            'qualification_type' => ['REATTESTATION', 'SPECIALIZATION', 'CLINICAL_RESIDENCY', 'INTERNSHIP',
                                     'STAZHUVANNYA', 'POSTGRADUATE', 'TOPIC_IMPROVEMENT'],
            'speciality_level' => ['FIRST', 'SECOND', 'SPECIALIST', 'HIGHEST', 'NOT_APPLICABLE'],
            'speciality_qualification_type' => ['AWARDING', 'DEFENSE'],
        ],
        'MED_COORDINATOR' => [
            'position' => ['P280'],
            'speciality_type' => $specialistSpecialityTypes,
            'education_degree' => ['EXPERT', 'MASTER', 'BACHELOR', 'JUNIOR_EXPERT'],
            'qualification_type' => ['INFORMATION_COURSES', 'STAZHUVANNYA'],
            'speciality_level' => ['FIRST', 'SECOND', 'HIGHEST', 'NOT_APPLICABLE'],
            'speciality_qualification_type' => ['AWARDING', 'DEFENSE'],
        ],
        'NHS_ADMIN' => [
            'position' => ['P27', 'P28', 'P29', 'P30', 'P31', 'P237', 'P238', 'P239'],
        ],
        'RECEPTIONIST' => [
            'position' => ['P15']
        ],
        'SPECIALIST' => [
            'position' => ['P5', 'P6', 'P8', 'P9', 'P10', 'P11', 'P12', 'P13', 'P33', 'P34', 'P35', 'P36', 'P37', 'P38',
                           'P39', 'P40', 'P41', 'P42', 'P43', 'P44', 'P45', 'P46', 'P47', 'P48', 'P49', 'P50', 'P51',
                           'P52', 'P53', 'P54', 'P55', 'P56', 'P57', 'P58', 'P59', 'P60', 'P61', 'P62', 'P63', 'P64',
                           'P65', 'P67', 'P68', 'P69', 'P70', 'P71', 'P72', 'P73', 'P74', 'P75', 'P76', 'P77', 'P78',
                           'P79', 'P80', 'P81', 'P82', 'P83', 'P84', 'P85', 'P86', 'P87', 'P88', 'P89', 'P90', 'P91',
                           'P92', 'P93', 'P94', 'P95', 'P96', 'P97', 'P98', 'P99', 'P100', 'P101', 'P102', 'P103',
                           'P104', 'P105', 'P106', 'P107', 'P108', 'P109', 'P110', 'P111', 'P112', 'P113', 'P114',
                           'P115', 'P116', 'P117', 'P118', 'P119', 'P120', 'P121', 'P122', 'P123', 'P124', 'P125',
                           'P126', 'P127', 'P128', 'P129', 'P130', 'P131', 'P132', 'P133', 'P134', 'P135', 'P136',
                           'P137', 'P138', 'P139', 'P140', 'P141', 'P142', 'P143', 'P144', 'P145', 'P146', 'P147',
                           'P148', 'P149', 'P150', 'P151', 'P152', 'P153', 'P154', 'P155', 'P156', 'P157', 'P158',
                           'P159', 'P160', 'P161', 'P162', 'P163', 'P164', 'P165', 'P166', 'P167', 'P228', 'P248',
                           'P245', 'P246', 'P258', 'P266', 'P267', 'P268', 'P269', 'P270', 'P282', 'P283'],
            'speciality_type' => $specialistSpecialityTypes,
            'education_degree' => ['EXPERT', 'MASTER', 'BACHELOR', 'JUNIOR_EXPERT'],
            'qualification_type' => ['CLINICAL_RESIDENCY', 'INTERNSHIP', 'REATTESTATION', 'SPECIALIZATION',
                                     'STAZHUVANNYA', 'POSTGRADUATE', 'TOPIC_IMPROVEMENT'],
            'speciality_level' => ['FIRST', 'SECOND', 'HIGHEST', 'BASIC', 'NOT_APPLICABLE'],
            'speciality_qualification_type' => ['AWARDING', 'DEFENSE'],
        ],
        'MED_ADMIN' => [
            'position' => ['P1', 'P2', 'P3', 'P4', 'P5', 'P6', 'P23', 'P24', 'P25', 'P26', 'P32', 'P229',
                           'P230', 'P231', 'P249', 'P257'],

            'speciality_type' => $specialistSpecialityTypes,
            'education_degree' => ['EXPERT', 'MASTER', 'BACHELOR', 'JUNIOR_EXPERT'],
            'qualification_type' => ['INFORMATION_COURSES', 'STAZHUVANNYA'],
            'speciality_level' => ['FIRST', 'SECOND', 'HIGHEST', 'NOT_APPLICABLE'],
            'speciality_qualification_type' => ['AWARDING', 'DEFENSE'],
        ],
    ],

    /*
  |--------------------------------------------------------------------------
  | Employee Types Requiring Medical/Professional Data
  |--------------------------------------------------------------------------
  | These roles mandate the presence of education, specialties,
  | qualifications, and science degree blocks in the eHealth request.
  */
    'medical_employees' => [
        'DOCTOR',
        'SPECIALIST',
        'ASSISTANT',
        'PHARMACIST',
        'MED_ADMIN',
        'LABORANT',
        'MED_COORDINATOR',
    ],

    // admin group
    'administrative_employees' => [
        'OWNER',
        'HR',
        'ACCOUNTANT',
        'PHARMACY_OWNER',
    ],

    'pharmacy_employee_types' => [
        'PHARMACIST',
        'PHARMACY_OWNER',
    ],

    'no_tax_id_document_types' => [
        'PASSPORT', 'NATIONAL_ID', 'REFUGEE_CERTIFICATE', 'PERMANENT_RESIDENCE_PERMIT',
    ],
    'employee_identity_document_types' => [
        // EMPLOYEE_IDENTITY_DOCUMENT_TYPES — chart parameter, API-005-024-0001 §9 Validate request (Logic)
        // https://e-health-ua.atlassian.net/wiki/spaces/ESOZ/pages/17570365551/DRAFT+REST+API+Create+Employee+Request+v2+API-005-024-0001
        'COMPLEMENTARY_PROTECTION_CERTIFICATE', 'NATIONAL_ID', 'PASSPORT', 'PERMANENT_RESIDENCE_PERMIT', 'REFUGEE_CERTIFICATE',
        'TEMPORARY_CERTIFICATE', 'TEMPORARY_PASSPORT',
    ],

    // https://e-health-ua.atlassian.net/wiki/spaces/ESOZ/pages/20213956636/DRAFT+Config+params+Legal+Entity+ENT-035
    'legal_entity_primary_care_providing_conditions' => ['OUTPATIENT'],
    'legal_entity_outpatient_providing_conditions' => ['INPATIENT', 'OUTPATIENT', 'FIELD'],
    'legal_entity_emergency_providing_conditions' => ['FIELD'],

    // Additional license types allowed per legal entity type (LEGAL_ENTITY_<LEGAL_ENTITY_TYPE>_ADDITIONAL_LICENSE_TYPES)
    // https://e-health-ua.atlassian.net/wiki/spaces/EH/pages/17092870145/Legal+Entities+configurable+parameters
    'legal_entity_outpatient_additional_license_types' => ['PHARMACY_DRUGS'],
    'legal_entity_pharmacy_additional_license_types' => ['PHARMACY_DRUGS'],

    // Set the test environment
    'test' => [
        'client_id' => env('TEST_CLIENT_ID'),
        'client_secret' => env('TEST_CLIENT_SECRET'),
        'emails' => env('TEST_CLIENT_EMAILS') ? explode(',', env('TEST_CLIENT_EMAILS')) : [],
    ],

    // https://e-health-ua.atlassian.net/wiki/spaces/ESOZ/pages/19600179308/All+Scopes+model
    'roles' => include config_path('scopes/roles.php'),

    'emailers' => [
        'credentialsQueueTimeout' => 60,
        'failCredentialsTries' => 3
    ],

    'frontend_date_format' => [
        'd.m.Y' => 'dd.mm.yyyy',
        'd/m/Y' => 'dd/mm/yyyy',
        'Y-m-d' => 'yyyy-mm-dd',
    ],

    'migrations' => [
        'install' => [
            'path' => 'database/migrations/install'
        ],
        'update' => [
            'version' => [
                'prev' => '',
                'curr' => env('APP_VERSION', '0.1')
            ],
            'path' => 'database/migrations/update'
        ]
    ],

    // https://docs.google.com/spreadsheets/d/1LeeQv42c3soY2_LLNzaAk7OqG5Hel38X2_n8sclXytA/edit?gid=216664394#gid=216664394
    'medications_atc_code' => [
        'A10AE54', 'A10AC01', 'S01ED01', 'N03AG01', 'R03AC02', 'R03АС02', 'C01BD01', 'C09AA02', 'C03AA03', 'A10AE06',
        'N03AX09', 'S03AA07', 'A10AD01', 'C03DA01', 'L04AD02', 'N05AX08', 'R03AK07', 'C08CA01', 'C08СА01', 'С08СА01',
        'R03BA02', 'H02AB06', 'C09DA07', 'C03CA04', 'B01AA03', 'A10AB01', 'C07AB07', 'C07АВ07', 'С07АВ07', 'L04AD01',
        'B01AC06', 'N05AH02', 'M04AA01', 'N03AF01', 'N05BA01', 'C02AB01', 'N02AB03', 'R03BB01', 'L04AA06', 'C08DA01',
        'C08CA05', 'C10AA01', 'С10AА01', 'R03BA01', 'N05AH04', 'J01XD01', 'H03AA01', 'A10AE56', 'C01DA02', 'С01DА02',
        'N06AB10', 'C07AA05', 'B01AC04', 'В01АС04', 'N03AB02', 'A10AE05', 'N02AA01', 'A10AB06', 'N05AD01', 'N06AB06',
        'N05AX12', 'N06AB03', 'L04AA18', 'S01EE01', 'A10BB09', 'A10BA02', 'А10ВА02', 'C08DА01', 'G02CB03', 'C07AG02',
        'M01CC01', 'A10AE04', 'C09BA03', 'С07АВ03', 'C09СA01', 'C09CA01', 'С09СА01', 'N06AA09', 'C03BA11', 'A10AB05',
        'A07DA03', 'B03BB01', 'C01AA05', 'H01BA02', 'R03AK06', 'C07АG02', 'С07AG02', 'S01EC01', 'S01AA26', 'C07AB02',
        'С07AB02', 'C09АА02', 'С09АА02', 'N04BA02', 'A10AD06', 'N06AB05', 'N02CC01', 'J05AB14', 'N03AA02', 'C09DB04',
        'N03AX14', 'R03BB04', 'C01DA08', 'A10BB01', 'А10ВВ01', 'N07AA02', 'H02AB09', 'C03CA01', 'С03СА01', 'N04AA02',
        'C07AB03', 'A03FA01', 'L02BA01', 'L02BG06', 'L02BG04', 'A10AD05',
    ]
];
