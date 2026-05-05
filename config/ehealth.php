<?php

declare(strict_types=1);

use App\Models\LegalEntity;

return [
    'api' => [
        'domain' => env('EHEALTH_API_URL', 'private-anon-cb2ce4f7fc-uaehealthapi.apiary-mock.com'),
        'token' => env('EHEALTH_X_CUSTOM_PSK', 'X-Custom-PSK'),
        'api_key' => env('EHEALTH_API_KEY', ''),
        'mis_api_key' => env('EHEALTH_MIS_API_KEY', ''),
        'mis_token' => env('EHEALTH_MIS_TOKEN'),
        'mis_id' => env('EHEALTH_MIS_ID'),
        'callback_prod' => env('EHEALTH_CALLBACK_PROD', true),
        'auth_host' => env('EHEALTH_AUTH_HOST', 'https://auth-preprod.ehealth.gov.ua'),
        'redirect_uri' => env('EHEALTH_REDIRECT_URI', 'https://openhealths.com/ehealth/oauth'),
        'url_dev' => env('EHEALTH_URL_DEV', 'http://localhost'),
        'auth_ehealth' => env('EHEALTH_CODE_TOKEN', 'user_id_auth_ehealth'),
        'oauth' => [
            'bearer_token' => env('EHEALTH_OAUTH_TOKEN', 'auth_token'),
            'tokens' => env('EHEALTH_OAUTH_TOKENS', '/oauth/tokens'),
            'user' => env('EHEALTH_OAUTH_USER', '/oauth/user'),
            'logout' => env('EHEALTH_OAUTH_LOGOUT', '/auth/logout')
        ],
        'timeout' => 10,
        'queueTimeout' => 60,
        'cooldown' => 300,
        'retries' => 10,
        'page_size' => env('EHEALTH_PAGE_SIZE', 300),
        'page_size_max' => env('EHEALTH_PAGE_SIZE_MAX', 500)
    ],

    'auth' => [
        'delay_seconds' => 300,     // Amount of the seconds to another login attempt
        'max_login_attempts' => 5   // Amount of the wrong attempt before locking out
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

    'rate_limit' => [
        'employee_request' => 29,
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
        'employee_role' => 50,
        'party_request' => 30,
        'declaration' => 10,
        'declaration_request' => 20
    ],
    'employee_type' => [
        'OWNER' => [
            'position' => [
                'P1', 'P2', 'P3', 'P4', 'P5', 'P6', 'P18', 'P19', 'P22', 'P23', 'P24', 'P25', 'P26', 'P32', 'P229',
                'P230', 'P231', 'P232', 'P233', 'P234', 'P235', 'P236', 'P237', 'P238', 'P239', 'P240', 'P247', 'P249', 'P257'
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
                                  'CLINICAL_PROVISOR', 'PHARMACIST'],
            'education_degree' => ['EXPERT', 'MASTER', 'BACHELOR', 'JUNIOR_EXPERT'],
            'qualification_type' => ['REATTESTATION', 'SPECIALIZATION', 'STAZHUVANNYA', 'POSTGRADUATE'],
            'speciality_level' => ['FIRST', 'SECOND', 'HIGHEST', 'NOT_APPLICABLE'],
        ],
        'ADMIN' => [
            'position' => [
                ' P5', 'P6', 'P14', 'P18', 'P19'
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
                                  'CLINICAL_PSYCHOLOGY'],
            'education_degree' => ['EXPERT', 'MASTER', 'BACHELOR', 'JUNIOR_EXPERT', 'JUNIOR_BACHELOR'],
            'qualification_type' => ['CLINICAL_RESIDENCY', 'INTERNSHIP', 'REATTESTATION', 'SPECIALIZATION',
                                     'STAZHUVANNYA', 'POSTGRADUATE', 'TOPIC_IMPROVEMENT'],
            'speciality_level' => ['FIRST', 'SECOND', 'HIGHEST', 'BASIC', 'NOT_APPLICABLE'],
            'speciality_qualification_type' => ['AWARDING', 'DEFENSE'],
        ],
        'DOCTOR' => [
            'position' => ['P7', 'P8', 'P9', 'P10', 'P11'],
            'speciality_type' => ['FAMILY_DOCTOR', 'PEDIATRICIAN', 'THERAPIST'],
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
                                  'CYTOMORPHOLOGY_CLINICAL_DIAGNOSTIC'],
            'education_degree' => ['MASTER', 'EXPERT', 'BACHELOR', 'JUNIOR_EXPERT'],
            'qualification_type' => ['REATTESTATION', 'SPECIALIZATION', 'CLINICAL_RESIDENCY', 'INTERNSHIP',
                                     'STAZHUVANNYA', 'POSTGRADUATE', 'TOPIC_IMPROVEMENT'],
            'speciality_level' => ['FIRST', 'SECOND', 'SPECIALIST', 'HIGHEST', 'NOT_APPLICABLE'],
            'speciality_qualification_type' => ['AWARDING', 'DEFENSE'],
        ],
        'MED_COORDINATOR' => [
            'position' => ['P280'],
            'speciality_type' => ['PHYSICAL_THERAPEUTICS', 'ERGOTHERAPEUTICS', 'IMMUNOLOGY', 'INFECTIOUS_DISEASES',
                                  'CARDIOLOGY', 'CLINICAL_BIOCHEMISTRY', 'CLINICAL_IMMUNOLOGY', 'CLINICAL_DIAGNOSTIC',
                                  'COMBUSTIOLOGY', 'COMMUNAL_HYGIENE', 'LABORATORY_IMMUNOLOGY',
                                  'LABORATORY_RESEARCH_OF_ENVIRONMENTAL_FACTORS',
                                  'LABORATORY_RESEARCH_OF_ENVIRONMENT_PHYSICAL_FACTORS',
                                  'LABORATORY_RESEARCH_OF_ENVIRONMENT_CHEMICAL_FACTORS',
                                  'PHYSICAL_THERAPY', 'PHYSICAL_THERAPY_AND_SPORTS_MEDICINE', 'EMERGENCY_MEDICINE',
                                  'MEDICAL_PSYCHOLOGY', 'MICROBIOLOGY', 'NARCOLOGY', 'TRADITIONAL_ALTERNATIVE_MEDICINE',
                                  'NEUROLOGY', 'NEUROSURGERY', 'NEONATOLOGY', 'NEPHROLOGY', 'GYNECOLOGIC_ONCOLOGY',
                                  'ONCOLOGY', 'ONCOTOLARYNGOLOGY', 'SURGICAL_ONCOLOGY', 'PUBLIC_HEALTH_ORGANIZATION',
                                  'ORTHODONTOLOGY', 'ORTHOPEDIC_DENTISTRY', 'ORTHOPAEDICS', 'OTORHINOLARYNGOLOGY',
                                  'OPHTHALMOLOGY', 'PARASITOLOGY', 'PATHOLOGIC_ANATOMY', 'PEDIATRICIAN',
                                  'ADOLESCENT_MEDICINE', 'PROCTOLOGY', 'RADIATION_THERAPY', 'OCCUPATIONAL_PATHOLOGY',
                                  'PSYCHIATRY', 'PSYCHOTHERAPY', 'PSYCHOPHYSIOLOGY', 'PULMONOLOGY', 'RADIATION_HYGIENE',
                                  'RADIOLOGY', 'RADIOLOGIC_DIAGNOSIS', 'RHEUMATOLOGY', 'X_RAY_RADIOLOGY', 'REFLEXOLOGY',
                                  'SANOLOGY', 'SEXOPATHOLOGY', 'SPORTS_MEDICINE', 'STOMATOLOGY', 'VASCULAR_SURGERY',
                                  'FORENSIC_MEDICINE', 'FORENSIC_MEDICAL_HISTOLOGY', 'FORENSIC_MEDICAL_EXAMINATION',
                                  'FORENSIC_IMMUNOLOGY', 'FORENSIC_CRIMINOLOGY', 'FORENSIC_MEDICAL_TOXICOLOGY',
                                  'FORENSIC_CYTOLOGY', 'FORENSIC_PSYCHIATRIC_EXAMINATION', 'AUDIOLOGY',
                                  'THERAPEUTIC_DENTISTRY', 'THERAPIST', 'TOXICOLOGY', 'THORACIC_SURGERY',
                                  'TRANSPLANTOLOGY', 'TRANSFUSIOLOGY', 'ULTRASONIC_DIAGNOSIS', 'UROLOGY',
                                  'PHYSIOTHERAPY', 'PHYSICAL_MEDICINE_AND_REHABILITATION', 'PHTHISIOLOGY',
                                  'FUNCTIONAL_DIAGNOSTICS', 'SURGICAL_DENTISTRY', 'GENERAL_SURGERY',
                                  'CARDIOVASCULAR_SURGERY', 'AEROSPACE_MEDICINE', 'OBSTETRICS_AND_GYNECOLOGY',
                                  'ALLERGOLOGY', 'ANAESTHETICS', 'BACTERIOLOGY', 'VIROLOGY', 'GASTROENTEROLOGY',
                                  'GENERAL_HEMATOLOGY', 'LABORATORY_GENETICS', 'MEDICAL_GENETICS', 'GERIATRICS',
                                  'PEDIATRIC_HYGIENE', 'OCCUPATIONAL_MEDICINE', 'FOOD_HYGIENE', 'DISINFECTION_',
                                  'DERMATO-VENEREOLOGY', 'PEDIATRIC_ALLERGY', 'PEDIATRIC_ANAESTHETICS',
                                  'PEDIATRIC_GASTROENTEROLOGY', 'PEDIATRIC_HEMATOLOGY', 'PEDIATRIC_GYNECOLOGY',
                                  'PEDIATRIC_DERMATO-VENEREOLOGY', 'PEDIATRIC_ENDOCRINOLOGY', 'PEDIATRIC_IMMUNOLOGY',
                                  'PEDIATRIC_CARDIOLOGY', 'PEDIATRIC_NEUROLOGY', 'PEDIATRIC_NEPHROLOGY',
                                  'PEDIATRIC_ONCOLOGY', 'PEDIATRIC_ORTHOPAEDICS', 'PEDIATRIC_OTOLARYNGOLOGY',
                                  'PEDIATRIC_OPHTHALMOLOGY', 'PEDIATRIC_PATHOLOGY', 'PEDIATRIC_PSYCHIATRY',
                                  'PEDIATRIC_PULMONOLOGY', 'PEDIATRIC_STOMATOLOGY', 'PEDIATRIC_UROLOGY',
                                  'PEDIATRIC_PHTHISIOLOGY', 'PEDIATRIC_SURGERY', 'PEDIATRIC_INFECTIOUS_DISEASE',
                                  'DIETETICS', 'ENDOCRINOLOGY', 'ENDOSCOPY', 'EPIDEMIOLOGY', 'COMMON_HYGIENE',
                                  'PEDIATRIC_HEMATOLOGY_AND_ONCOLOGY', 'INVASIVE_ELECTROPHYSIOLOGY',
                                  'INTERVENTIONAL_CARDIOLOGY', 'PEDIATRIC_NEUROLOGICAL_SURGERY', 'PERIODONTOLOGY',
                                  'PLASTIC_SURGERY', 'ORAL_AND_MAXILLOFACIAL_SURGERY', 'CHILD_CARDIOLOGY',
                                  'PEDIATRIC_RHEUMATOLOGY', 'SURGICAL_DERMATOLOGY'],
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
            'speciality_type' => ['PHYSICAL_THERAPEUTICS', 'ERGOTHERAPEUTICS', 'IMMUNOLOGY', 'INFECTIOUS_DISEASES',
                                  'CARDIOLOGY', 'CLINICAL_BIOCHEMISTRY', 'CLINICAL_IMMUNOLOGY', 'CLINICAL_DIAGNOSTIC',
                                  'COMBUSTIOLOGY', 'COMMUNAL_HYGIENE', 'LABORATORY_IMMUNOLOGY',
                                  'LABORATORY_RESEARCH_OF_ENVIRONMENTAL_FACTORS',
                                  'LABORATORY_RESEARCH_OF_ENVIRONMENT_PHYSICAL_FACTORS',
                                  'LABORATORY_RESEARCH_OF_ENVIRONMENT_CHEMICAL_FACTORS',
                                  'PHYSICAL_THERAPY', 'PHYSICAL_THERAPY_AND_SPORTS_MEDICINE',
                                  'EMERGENCY_MEDICINE', 'MEDICAL_PSYCHOLOGY', 'MICROBIOLOGY',
                                  'NARCOLOGY', 'TRADITIONAL_ALTERNATIVE_MEDICINE', 'NEUROLOGY',
                                  'NEUROSURGERY', 'NEONATOLOGY', 'NEPHROLOGY', 'GYNECOLOGIC_ONCOLOGY', 'ONCOLOGY',
                                  'ONCOTOLARYNGOLOGY', 'SURGICAL_ONCOLOGY', 'PUBLIC_HEALTH_ORGANIZATION',
                                  'ORTHODONTOLOGY', 'ORTHOPEDIC_DENTISTRY', 'ORTHOPAEDICS', 'OTORHINOLARYNGOLOGY',
                                  'OPHTHALMOLOGY', 'PARASITOLOGY', 'PATHOLOGIC_ANATOMY', 'PEDIATRICIAN',
                                  'ADOLESCENT_MEDICINE', 'PROCTOLOGY', 'RADIATION_THERAPY', 'OCCUPATIONAL_PATHOLOGY',
                                  'PSYCHIATRY', 'PSYCHOTHERAPY', 'PSYCHOPHYSIOLOGY', 'PULMONOLOGY', 'RADIATION_HYGIENE',
                                  'RADIOLOGY', 'RADIOLOGIC_DIAGNOSIS', 'RHEUMATOLOGY', 'X_RAY_RADIOLOGY', 'REFLEXOLOGY',
                                  'SANOLOGY', 'SEXOPATHOLOGY', 'SPORTS_MEDICINE', 'STOMATOLOGY', 'VASCULAR_SURGERY',
                                  'FORENSIC_MEDICINE', 'FORENSIC_MEDICAL_HISTOLOGY', 'FORENSIC_MEDICAL_EXAMINATION',
                                  'FORENSIC_IMMUNOLOGY', 'FORENSIC_CRIMINOLOGY', 'FORENSIC_MEDICAL_TOXICOLOGY',
                                  'FORENSIC_CYTOLOGY', 'FORENSIC_PSYCHIATRIC_EXAMINATION', 'AUDIOLOGY',
                                  'THERAPEUTIC_DENTISTRY', 'THERAPIST', 'TOXICOLOGY', 'THORACIC_SURGERY',
                                  'TRANSPLANTOLOGY', 'TRANSFUSIOLOGY', 'ULTRASONIC_DIAGNOSIS', 'UROLOGY',
                                  'PHYSIOTHERAPY', 'PHYSICAL_MEDICINE_AND_REHABILITATION', 'PHTHISIOLOGY',
                                  'FUNCTIONAL_DIAGNOSTICS', 'SURGICAL_DENTISTRY', 'GENERAL_SURGERY',
                                  'CARDIOVASCULAR_SURGERY', 'AEROSPACE_MEDICINE', 'OBSTETRICS_AND_GYNECOLOGY',
                                  'ALLERGOLOGY', 'ANAESTHETICS', 'BACTERIOLOGY', 'VIROLOGY', 'GASTROENTEROLOGY',
                                  'GENERAL_HEMATOLOGY', 'LABORATORY_GENETICS', 'MEDICAL_GENETICS', 'GERIATRICS',
                                  'PEDIATRIC_HYGIENE', 'OCCUPATIONAL_MEDICINE', 'FOOD_HYGIENE', 'DISINFECTION_',
                                  'DERMATO-VENEREOLOGY', 'PEDIATRIC_ALLERGY', 'PEDIATRIC_ANAESTHETICS',
                                  'PEDIATRIC_GASTROENTEROLOGY', 'PEDIATRIC_HEMATOLOGY', 'PEDIATRIC_GYNECOLOGY',
                                  'PEDIATRIC_DERMATO-VENEREOLOGY', 'PEDIATRIC_ENDOCRINOLOGY', 'PEDIATRIC_IMMUNOLOGY',
                                  'PEDIATRIC_CARDIOLOGY', 'PEDIATRIC_NEUROLOGY', 'PEDIATRIC_NEPHROLOGY',
                                  'PEDIATRIC_ONCOLOGY', 'PEDIATRIC_ORTHOPAEDICS', 'PEDIATRIC_OTOLARYNGOLOGY',
                                  'PEDIATRIC_OPHTHALMOLOGY', 'PEDIATRIC_PATHOLOGY', 'PEDIATRIC_PSYCHIATRY',
                                  'PEDIATRIC_PULMONOLOGY', 'PEDIATRIC_STOMATOLOGY', 'PEDIATRIC_UROLOGY',
                                  'PEDIATRIC_PHTHISIOLOGY', 'PEDIATRIC_SURGERY', 'PEDIATRIC_INFECTIOUS_DISEASE',
                                  'DIETETICS', 'ENDOCRINOLOGY', 'ENDOSCOPY', 'EPIDEMIOLOGY', 'COMMON_HYGIENE',
                                  'PEDIATRIC_HEMATOLOGY_AND_ONCOLOGY', 'INVASIVE_ELECTROPHYSIOLOGY',
                                  'INTERVENTIONAL_CARDIOLOGY', 'PEDIATRIC_NEUROLOGICAL_SURGERY', 'PERIODONTOLOGY',
                                  'PLASTIC_SURGERY', 'ORAL_AND_MAXILLOFACIAL_SURGERY', 'CHILD_CARDIOLOGY',
                                  'PEDIATRIC_RHEUMATOLOGY', 'SURGICAL_DERMATOLOGY'],
            'education_degree' => ['EXPERT', 'MASTER', 'BACHELOR', 'JUNIOR_EXPERT'],
            'qualification_type' => ['CLINICAL_RESIDENCY', 'INTERNSHIP', 'REATTESTATION', 'SPECIALIZATION',
                                     'STAZHUVANNYA', 'POSTGRADUATE', 'TOPIC_IMPROVEMENT'],
            'speciality_level' => ['FIRST', 'SECOND', 'HIGHEST', 'BASIC', 'NOT_APPLICABLE'],
            'speciality_qualification_type' => ['AWARDING', 'DEFENSE'],
        ],
        'MED_ADMIN' => [
            'position' => ['P1', 'P2', 'P3', 'P4', 'P5', 'P6', 'P23', 'P24', 'P25', 'P26', 'P32', 'P229',
                           'P230', 'P231', 'P249', 'P257'],

            'speciality_type' => [
                'PHYSICAL_THERAPEUTICS', 'ERGOTHERAPEUTICS', 'IMMUNOLOGY', 'INFECTIOUS_DISEASES',
                'CARDIOLOGY', 'CLINICAL_BIOCHEMISTRY', 'CLINICAL_IMMUNOLOGY', 'CLINICAL_DIAGNOSTIC',
                'COMBUSTIOLOGY', 'COMMUNAL_HYGIENE', 'LABORATORY_IMMUNOLOGY',
                'LABORATORY_RESEARCH_OF_ENVIRONMENTAL_FACTORS',
                'LABORATORY_RESEARCH_OF_ENVIRONMENT_PHYSICAL_FACTORS',
                'LABORATORY_RESEARCH_OF_ENVIRONMENT_CHEMICAL_FACTORS',
                'PHYSICAL_THERAPY', 'PHYSICAL_THERAPY_AND_SPORTS_MEDICINE', 'EMERGENCY_MEDICINE',
                'MEDICAL_PSYCHOLOGY', 'MICROBIOLOGY', 'NARCOLOGY', 'TRADITIONAL_ALTERNATIVE_MEDICINE',
                'NEUROLOGY', 'NEUROSURGERY', 'NEONATOLOGY', 'NEPHROLOGY', 'GYNECOLOGIC_ONCOLOGY',
                'ONCOLOGY', 'ONCOTOLARYNGOLOGY', 'SURGICAL_ONCOLOGY', 'PUBLIC_HEALTH_ORGANIZATION',
                'ORTHODONTOLOGY', 'ORTHOPEDIC_DENTISTRY', 'ORTHOPAEDICS', 'OTORHINOLARYNGOLOGY',
                'OPHTHALMOLOGY', 'PARASITOLOGY', 'PATHOLOGIC_ANATOMY', 'PEDIATRICIAN',
                'ADOLESCENT_MEDICINE', 'PROCTOLOGY', 'RADIATION_THERAPY', 'OCCUPATIONAL_PATHOLOGY',
                'PSYCHIATRY', 'PSYCHOTHERAPY', 'PSYCHOPHYSIOLOGY', 'PULMONOLOGY', 'RADIATION_HYGIENE',
                'RADIOLOGY', 'RADIOLOGIC_DIAGNOSIS', 'RHEUMATOLOGY', 'X_RAY_RADIOLOGY', 'REFLEXOLOGY',
                'SANOLOGY', 'SEXOPATHOLOGY', 'SPORTS_MEDICINE', 'STOMATOLOGY', 'VASCULAR_SURGERY',
                'FORENSIC_MEDICINE', 'FORENSIC_MEDICAL_HISTOLOGY', 'FORENSIC_MEDICAL_EXAMINATION',
                'FORENSIC_IMMUNOLOGY', 'FORENSIC_CRIMINOLOGY', 'FORENSIC_MEDICAL_TOXICOLOGY',
                'FORENSIC_CYTOLOGY', 'FORENSIC_PSYCHIATRIC_EXAMINATION', 'AUDIOLOGY',
                'THERAPEUTIC_DENTISTRY', 'THERAPIST', 'TOXICOLOGY', 'THORACIC_SURGERY',
                'TRANSPLANTOLOGY', 'TRANSFUSIOLOGY', 'ULTRASONIC_DIAGNOSIS', 'UROLOGY',
                'PHYSIOTHERAPY', 'PHYSICAL_MEDICINE_AND_REHABILITATION', 'PHTHISIOLOGY',
                'FUNCTIONAL_DIAGNOSTICS', 'SURGICAL_DENTISTRY', 'GENERAL_SURGERY',
                'CARDIOVASCULAR_SURGERY', 'AEROSPACE_MEDICINE', 'OBSTETRICS_AND_GYNECOLOGY',
                'ALLERGOLOGY', 'ANAESTHETICS', 'BACTERIOLOGY', 'VIROLOGY', 'GASTROENTEROLOGY',
                'GENERAL_HEMATOLOGY', 'LABORATORY_GENETICS', 'MEDICAL_GENETICS', 'GERIATRICS',
                'PEDIATRIC_HYGIENE', 'OCCUPATIONAL_MEDICINE', 'FOOD_HYGIENE', 'DISINFECTION_',
                'DERMATO-VENEREOLOGY', 'PEDIATRIC_ALLERGY', 'PEDIATRIC_ANAESTHETICS',
                'PEDIATRIC_GASTROENTEROLOGY', 'PEDIATRIC_HEMATOLOGY', 'PEDIATRIC_GYNECOLOGY',
                'PEDIATRIC_DERMATO-VENEREOLOGY', 'PEDIATRIC_ENDOCRINOLOGY', 'PEDIATRIC_IMMUNOLOGY',
                'PEDIATRIC_CARDIOLOGY', 'PEDIATRIC_NEUROLOGY', 'PEDIATRIC_NEPHROLOGY',
                'PEDIATRIC_ONCOLOGY', 'PEDIATRIC_ORTHOPAEDICS', 'PEDIATRIC_OTOLARYNGOLOGY',
                'PEDIATRIC_OPHTHALMOLOGY', 'PEDIATRIC_PATHOLOGY', 'PEDIATRIC_PSYCHIATRY',
                'PEDIATRIC_PULMONOLOGY', 'PEDIATRIC_STOMATOLOGY', 'PEDIATRIC_UROLOGY',
                'PEDIATRIC_PHTHISIOLOGY', 'PEDIATRIC_SURGERY', 'PEDIATRIC_INFECTIOUS_DISEASE',
                'DIETETICS', 'ENDOCRINOLOGY', 'ENDOSCOPY', 'EPIDEMIOLOGY', 'COMMON_HYGIENE',
                'PEDIATRIC_HEMATOLOGY_AND_ONCOLOGY', 'INVASIVE_ELECTROPHYSIOLOGY',
                'INTERVENTIONAL_CARDIOLOGY', 'PEDIATRIC_NEUROLOGICAL_SURGERY', 'PERIODONTOLOGY',
                'PLASTIC_SURGERY', 'ORAL_AND_MAXILLOFACIAL_SURGERY', 'CHILD_CARDIOLOGY',
                'PEDIATRIC_RHEUMATOLOGY', 'SURGICAL_DERMATOLOGY'
            ],
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
        'PHARMASIST', ' PHARMACY_OWNER'
    ],
    // https://e-health-ua.atlassian.net/wiki/spaces/EH/pages/583402009/Medical+Events+Dictionaries+and+configurations#legal_entity_encounter_classes
    'legal_entity_encounter_classes' => [
        'PRIMARY_CARE' => ['PHC'],
        'MSP' => ['PHC'],
        'OUTPATIENT' => ['AMB', 'INPATIENT']
    ],
    // https://e-health-ua.atlassian.net/wiki/spaces/EH/pages/583402009/Medical+Events+Dictionaries+and+configurations#performer_employee_encounter_classes
    'performer_employee_encounter_classes' => [
        'DOCTOR' => ['PHC'],
        'SPECIALIST' => ['AMB', 'INPATIENT'],
        'ASSISTANT' => ['PHC', 'AMB', 'INPATIENT'],
        'MED_COORDINATOR' => ['AMB']
    ],
    // https://e-health-ua.atlassian.net/wiki/spaces/EH/pages/583402009/Medical+Events+Dictionaries+and+configurations#performer_employee_encounter_types
    'performer_employee_encounter_types' => [
        'SPECIALIST' => ['service_delivery_location', 'virtual', 'patient_identity', 'discharge', 'field', 'home', 'covid', 'intervention', 'concilium'],
        'DOCTOR' => ['service_delivery_location', 'virtual', 'home', 'field', 'intervention'],
        'ASSISTANT' => ['intervention'],
        'MED_COORDINATOR' => ['service_delivery_location', 'virtual']
    ],
    // https://e-health-ua.atlassian.net/wiki/spaces/EH/pages/583402009/Medical+Events+Dictionaries+and+configurations#encounter_class_encounter_types
    'encounter_class_encounter_types' => [
        'AMB' => ['service_delivery_location', 'virtual', 'patient_identity', 'field', 'home', 'intervention', 'concilium'],
        'INPATIENT' => ['patient_identity', 'discharge', 'service_delivery_location', 'intervention', 'concilium'],
        'PHC' => ['service_delivery_location', 'virtual', 'home', 'field', 'intervention']
    ],
    // https://e-health-ua.atlassian.net/wiki/spaces/EH/pages/583402009/Medical+Events+Dictionaries+and+configurations#legal_entity_%3CLEGAL_ENTITY_TYPE%3E_episode_types
    'legal_entity_episode_types' => [
        'OUTPATIENT' => ['TREATMENT', 'PREVENTION', 'PALLIATIVE_CARE', 'DG', 'REHAB', 'CONDITIONING'],
        'PRIMARY_CARE' => ['TREATMENT', 'PREVENTION', 'PALLIATIVE_CARE', 'PHC'],
        'MSP' => ['TREATMENT', 'PHC', 'PREVENTION', 'PALLIATIVE_CARE'],
        'MSP_PHARMACY' => ['TREATMENT', 'PREVENTION', 'PALLIATIVE_CARE']
    ],
    // https://e-health-ua.atlassian.net/wiki/spaces/EH/pages/583402009/Medical+Events+Dictionaries+and+configurations#episode_type_%3CeHealth%2Fepisode_types%3E_encounter_classes--dynamic-configuration-for-episode-types
    'episode_type_encounter_classes' => [
        'TREATMENT' => ['AMD', 'PHC', 'INPATIENT'],
        'PREVENTION' => ['PHC', 'INPATIENT', 'AMB'],
        'DG' => ['AMB', 'INPATIENT'],
        'REHAB' => ['AMB', 'INPATIENT'],
        'PALLIATIVE_CARE' => ['INPATIENT', 'PHC', 'AMB'],
        'PHC' => ['PHC'],
        'CONDITIONING' => ['INPATIENT']
    ],
    // https://e-health-ua.atlassian.net/wiki/spaces/EH/pages/583402009/Medical+Events+Dictionaries+and+configurations#employee_%3CEMPLOYEE_TYPE%3E_episode_types
    'employee_episode_types' => [
        'SPECIALIST' => ['TREATMENT', 'PREVENTION', 'PALLIATIVE_CARE', 'DG', 'REHAB', 'CONDITIONING'],
        'DOCTOR' => ['TREATMENT', 'PREVENTION', 'PALLIATIVE_CARE', 'PHC'],
        'ASSISTANT' => ['TREATMENT'],
        'MED_COORDINATOR' => ['TREATMENT', 'DG']
    ],
    // https://e-health-ua.atlassian.net/wiki/spaces/EH/pages/17999298851/RC_+CSI-1323+_Create+Update+person+request+v2#Validate-person-documents
    'expiration_date_exists' => [
        'NATIONAL_ID', 'COMPLEMENTARY_PROTECTION_CERTIFICATE', 'PERMANENT_RESIDENCE_PERMIT', 'REFUGEE_CERTIFICATE',
        'TEMPORARY_CERTIFICATE', 'TEMPORARY_PASSPORT'
    ],
    // https://e-health-ua.atlassian.net/wiki/spaces/EH/pages/17999299028/Person+documents+configurable+parameters#Person-documents-configurable-parameters
    'self_auth_age_document_types' => [
        'COMPLEMENTARY_PROTECTION_CERTIFICATE', 'NATIONAL_ID', 'PASSPORT', 'PERMANENT_RESIDENCE_PERMIT',
        'REFUGEE_CERTIFICATE', 'TEMPORARY_CERTIFICATE', 'TEMPORARY_PASSPORT'
    ],
    'person_legal_capacity_document_types' => [
        'DIVORCE_CERTIFICATE', 'MARRIAGE_CERTIFICATE', 'STATE_REGISTER_EXTRACT', 'COURT_DECISION_LEGAL_CAPACITY',
        'COURT_DECISION_DIVORCE', 'GUARDIANSHIP_DECISION_LEGAL_CAPACITY', 'LEGAL_CAPACITY_DOCUMENT'
    ],
    'person_registration_document_types' => [
        'BIRTH_CERTIFICATE', 'BIRTH_CERTIFICATE_FOREIGN', 'COMPLEMENTARY_PROTECTION_CERTIFICATE', 'NATIONAL_ID',
        'PASSPORT', 'PERMANENT_RESIDENCE_PERMIT', 'REFUGEE_CERTIFICATE', 'TEMPORARY_CERTIFICATE', 'TEMPORARY_PASSPORT'
    ],
    // https://e-health-ua.atlassian.net/wiki/spaces/EH/pages/17678041168/Observation+dictionaries+and+configurations#observation_categories-vs-observation_codes
    'observation_category_codes' => [
        'exam' => [
            '29463-7', '8302-2', 'sex', 'weight_under_1_year', '8339-4', '8310-5', '21112-8', '56086-2', '80319-7',
            '82810-3', '8462-4', '8480-6', '8867-4', '9279-1'
        ],
        'vital-signs' => [
            'stature', 'eye_colour', 'hair_color', 'hair_length', 'beard', 'mustache', 'peculiarity', '31044-1', '30525-0'
        ],
        'social-history' => ['clothes', '85658-3', 'covid_vac_groups'],
        'survey' => [
            'APGAR_1', 'APGAR_5', '11884-4', '57722-1', '73773-4', '73771-8', '11638-4', '68496-9', '75859-9', '39156-5',
            '38214-3', '38215-0', '913921', 'PPS', '96761-2'
        ],
        'laboratory' => [
            '94762-2', '94558-4', '94500-6', '94562-6', '94564-2', '94563-4', '4548-4', '29572-5', '38473-5', '48633-2',
            '29575-8', '2762-3', '50106-4', '45207-8', '53166-5', '45216-9', '45211-0', '53175-6', '45197-1', '45199-7',
            '45200-3', '53192-1', '53191-3', '53190-5', '45198-9', '50125-4', '50132-0', '50113-0', '53187-1', '29293-8',
            '20661-5', '53160-8', '38481-8', '50157-7', '3077-5', '42906-8', '75217-0', '92002-5', '92006-6', '47679-6',
            '47799-2', '14743-9', '35571-9', '10331-7', '14578-9', '78014-8', '78015-5', '96636-6', '57290-9', '57291-7',
            '77636-9', '96664-8', '57293-3', '78017-1', '57299-0', '73809-6', '73807-0', '73808-8', '35471-2', '34960-5',
            '98007-8', '45153-4', '102113-8', '103154-1', '80698-4', '29770-5', '59822-7', '92728-5', '29247-4', '96462-7',
            '50544-6', '77349-9', '16703-1', '3520-4', '14978-1', '55805-6', '16419-4', '55806-4', '70211-8', '63557-3',
            '5196-1', '22316-4', '13952-7', '42595-9', '29610-3', '5193-8', '10900-9', '22327-1', '13955-0', '11011-4',
            '11259,-9', '63464-2', '22587-0', '7852-7', '22244-8', '30325-5', '7853-5', '13238-1', '49178-7', '8039-0',
            '22580-5', '94819-0', '94309-2', '2947-0', '6298-4', '1996-8', '2069-3', '3040-3', '15074-8', '2885-2',
            '1751-7', '6768-6', '14631-6', '14629-0', '1798-8', '1742-6', '1920-8', '59826-8', '72903-8', '32673-6',
            '2157-6', '42757-5', '2324-2', '1988-5', '14804-9', '14805-6', '2524-7', '33959-8', '33762-6', '48664-7',
            '5902-2', '6302-4', '34714-6', '3173-2', '27811-9', '11558-4', '11557-6', '11556-8', '1959-6', '29590-7',
            '30246-3', '14647-2'
        ],
        'procedure' => ['65897-1', '65893-0'],
        'therapy' => ['65897-1', '65893-0', '74200-7', '87238-2'],
        'imaging' => ['65897-1', '65893-0']
    ],
    // https://e-health-ua.atlassian.net/wiki/spaces/EH/pages/17678041168/Observation+dictionaries+and+configurations#eHealth%2FLOINC%2Fobservation_codes
    'observation_code_values' => [
        'functions' => ['', 'valueCodeableConcept', ''],
        'structures' => ['', 'valueCodeableConcept', ''],
        'activities' => ['', 'valueCodeableConcept', ''],
        'environmental' => ['', 'valueCodeableConcept', ''],

        'stature' => ['eHealth/stature', 'valueCodeableConcept', ''],
        'eye_colour' => ['eHealth/eye_colour', 'valueCodeableConcept', ''],
        'hair_color' => ['eHealth/hair_color', 'valueCodeableConcept', ''],
        'hair_length' => ['eHealth/hair_length', 'valueCodeableConcept', ''],
        'beard' => ['', 'valueBoolean', ''],
        'mustache' => ['', 'valueBoolean', ''],
        'clothes' => ['', 'valueString', ''],
        'peculiarity' => ['', 'valueString', ''],
        '31044-1' => ['', 'valueString', ''],
        '29463-7' => ['', 'valueQuantity', 'kg'],
        '8302-2' => ['', 'valueQuantity', 'cm'],
        'sex' => ['GENDER', 'valueCodeableConcept', ''],
        '10331-7' => ['eHealth/LOINC/LL360-9', 'valueCodeableConcept', ''], // TBD CR-200
        '14578-9' => ['eHealth/LOINC/LL2419-1', 'valueCodeableConcept', ''], // TBD CR-200
        '14743-9' => ['', 'valueQuantity', 'mmol/L'],
        '39156-5' => ['', 'valueQuantity', 'kg/m2'],
        '4548-4' => ['', 'valueQuantity', '%'],
        '56086-2' => ['', 'valueQuantity', 'cm'],
//         '80319-7' => ['', 'valueCodeableConcept', '], // TBD CR-200
        '82810-3' => ['eHealth/LOINC/LL4129-4', 'valueCodeableConcept', ''], // TBD CR-200
        '8310-5' => ['', 'valueQuantity', 'Cel'],
        '8462-4' => ['', 'valueQuantity', 'mm[Hg]'],
        '8480-6' => ['', 'valueQuantity', 'mm[Hg]'],
        '8867-4' => ['', 'valueQuantity', '{Beats}/min'],
        '9279-1' => ['', 'valueQuantity', '{Breaths}/min'],
        'APGAR_1' => ['0-10', 'valueQuantity', 'ScoreOf'],
        'APGAR_5' => ['0-10', 'valueQuantity', 'ScoreOf'],
        '11884-4' => ['', 'valueQuantity', 'wk'],
        '8339-4' => ['', 'valueQuantity', 'g'],
        '57722-1' => ['', 'valueBoolean', ''],
        '73773-4' => ['1-12', 'valueQuantity', '{#}'],
        '73771-8' => ['1-12', 'valueQuantity', ''],
        '11638-4' => ['0-20', 'valueQuantity', '{#}'],
        '68496-9' => ['0-20', 'valueQuantity', '{#}'],
        'weight_under_1_year' => ['', 'valueQuantity', 'g'],
        '75859-9' => ['eHealth/rankin_scale', 'valueCodeableConcept', ''],
        '94762-2' => ['eHealth/LOINC/LL2009-0', 'valueCodeableConcept', ''],
        '94558-4' => ['eHealth/LOINC/LL2021-5', 'valueCodeableConcept', ''],
        '94500-6' => ['eHealth/LOINC/LL2021-5', 'valueCodeableConcept', ''],
        '94562-6' => ['eHealth/LOINC/LL2009-0', 'valueCodeableConcept', ''],
        '94564-2' => ['eHealth/LOINC/LL2009-0', 'valueCodeableConcept', ''],
        '94563-4' => ['eHealth/LOINC/LL2009-0', 'valueCodeableConcept', ''],
        '85658-3' => ['eHealth/occupation_type', 'valueCodeableConcept', ''],
        '65897-1' => ['0-3', 'valueQuantity', '{#}'],
        '65893-0' => ['0-3', 'valueQuantity', '{#}'],
        '30525-0' => ['0-120', 'valueQuantity', 'a'],
        'covid_vac_groups' => ['eHealth/vaccination_covid_groups', 'valueCodeableConcept', ''],
        '29572-5' => ['0-50', 'valueQuantity', 'mg/dL'],
        '38473-5' => ['0-150', 'valueQuantity', 'ng/mL'],
        '48633-2' => ['0-150', 'valueQuantity', 'ug/L'],
        '29575-8' => ['0-15', 'valueQuantity', 'm[IU]/L'],
        '21112-8' => ['', 'valueDateTime', ''],
        '2762-3' => ['0-10', 'valueQuantity', 'mg/dL'],
        '50106-4' => ['0-1', 'valueQuantity', 'umol/L'],
        '45207-8' => ['0-1', 'valueQuantity', 'umol/L'],
        '53166-5' => ['0-10', 'valueQuantity', 'umol/L'],
        '45216-9' => ['0-10', 'valueQuantity', 'umol/L'],
        '45211-0' => ['0-0.5', 'valueQuantity', 'umol/L'],
        '53175-6' => ['0-0.5', 'valueQuantity', 'umol/L'],
        '45197-1' => ['0-0.5', 'valueQuantity', 'umol/L'],
        '45199-7' => ['0-0.5', 'valueQuantity', 'umol/L'],
        '45200-3' => ['0-0.5', 'valueQuantity', 'umol/L'],
        '53192-1' => ['0-1', 'valueQuantity', 'umol/L'],
        '53191-3' => ['0-0.5', 'valueQuantity', 'umol/L'],
        '53190-5' => ['0-0.5', 'valueQuantity', 'umol/L'],
        '45198-9' => ['0-0.5', 'valueQuantity', 'umol/L'],
        '50125-4' => ['0-0.5', 'valueQuantity', 'umol/L'],
        '50132-0' => ['0-0.5', 'valueQuantity', 'umol/L'],
        '50113-0' => ['0-0.5', 'valueQuantity', 'umol/L'],
        '53187-1' => ['0-0.5', 'valueQuantity', 'umol/L'],
        '29293-8' => ['0-10', 'valueQuantity', 'mg/dL'],
        '20661-5' => ['0-300', 'valueQuantity', 'umol/L'],
        '53160-8' => ['0-5', 'valueQuantity', 'umol/L'],
        '38481-8' => ['0-100', 'valueQuantity', 'umol/L'],
        '50157-7' => ['0-50', 'valueQuantity', 'umol/L'],
        '3077-5' => ['0-10', 'valueQuantity', 'mg/dL'],
        '42906-8' => ['0-100', 'valueQuantity', 'nmol/h/mL'],
        '75217-0' => ['0-0.5', 'valueQuantity', 'nmol/mL/min'],
        '92002-5' => ['0-100', 'valueQuantity', '{Ct_value}'],
        '92006-6' => ['0-100', 'valueQuantity', '{Ct_value}'],
        '47679-6' => ['', 'valueQuantity', 'umol/L'],
        '47799-2' => ['', 'valueQuantity', 'umol/L'],
        '35571-9' => ['0-1000', 'valueQuantity', 'umol/L'],
        '38214-3' => ['1-10', 'valueQuantity', 'ScoreOf'],
        '38215-0' => ['1-10', 'valueQuantity', 'ScoreOf'],
        '91392-1' => ['1-10', 'valueQuantity', 'ScoreOf'],
        '78014-8' => ['', 'valueString', ''],
        '78015-5' => ['', 'valueString', ''],
        '96636-6' => ['', 'valueString', ''],
        '57290-9' => ['', 'valueString', ''],
        '57291-7' => ['', 'valueString', ''],
        '77636-9' => ['', 'valueString', ''],
        '96664-8' => ['', 'valueString', ''],
        '57293-3' => ['', 'valueString', ''],
        '78017-1' => ['', 'valueString', ''],
        '57299-0' => ['', 'valueString', ''],
        '73809-6' => ['eHealth/LOINC/LL2451-4', 'valueCodeableConcept', ''],
        '73807-0' => ['eHealth/LOINC/LL2451-4', 'valueCodeableConcept', ''],
        '73808-8' => ['eHealth/LOINC/LL2451-4', 'valueCodeableConcept', ''],
        '35471-2' => ['eHealth/LOINC/LL360-9', 'valueCodeableConcept', ''],
        '34960-5' => ['eHealth/LOINC/LL2451-4', 'valueCodeableConcept', ''],
        '98007-8' => ['eHealth/LOINC/LL2451-4', 'valueCodeableConcept', ''],
        '45153-4' => ['eHealth/LOINC/LL2451-4', 'valueCodeableConcept', ''],
        '102113-8' => ['', 'valueQuantity', '%'],
        '103154-1' => ['', 'valueQuantity', '%'],
        '80698-4' => ['', 'valueQuantity', '%'],
        '29770-5' => ['', 'valueString', ''],
        '59822-7' => ['', 'valueQuantity', 'ug/L'],
        '92728-5' => ['', 'valueQuantity', 'ug/L'],
        '29247-4' => ['', 'valueQuantity', 'ng/mL'],
        '96462-7' => ['', 'valueQuantity', 'nmol/L'],
        '50544-6' => ['', 'valueQuantity', 'ng/mL'],
        '77349-9' => ['', 'valueQuantity', 'ng/mL'],
        '16703-1' => ['', 'valueQuantity', 'ng/mL'],
        '3520-4' => ['', 'valueQuantity', 'ng/mL'],
        '14978-1' => ['', 'valueQuantity', 'ug/L'],
        '55805-6' => ['', 'valueQuantity', 'ug/L'],
        '16419-4' => ['', 'valueQuantity', 'ng/mL'],
        '55806-4' => ['', 'valueQuantity', 'mg/L'],
        '70211-8' => ['', 'valueQuantity', 'umol/L'],
        '63557-3' => ['', 'valueQuantity', '[IU]/L'],
        '5196-1' => ['eHealth/LOINC/LL2451-4', 'valueCodeableConcept', ''],
        '22316-4' => ['', 'valueQuantity', "[arb'U]/mL"],
        '13952-7' => ['eHealth/LOINC/LL2451-4', 'valueCodeableConcept', ''],
        '42595-9' => ['', 'valueQuantity', '[IU]/mL'],
        '29610-3' => ['eHealth/LOINC/LL2451-4', 'valueCodeableConcept', ''],
        '5193-8' => ['', 'valueQuantity', 'm[IU]/mL'],
        '10900-9' => ['eHealth/LOINC/LL2451-4', 'valueCodeableConcept', ''],
        '22327-1' => ['', 'valueQuantity', '[IU]/mL'],
        '13955-0' => ['eHealth/LOINC/LL2451-4', 'valueCodeableConcept', ''],
        '11011-4' => ['', 'valueQuantity', '[IU]/mL'],
        '11259-9' => ['eHealth/LOINC/LL2451-4', 'valueCodeableConcept', ''],
        '63464-2' => ['', 'valueQuantity', '{index_val}'],
        '22587-0' => ['eHealth/LOINC/LL2451-4', 'valueCodeableConcept', ''],
        '7852-7' => ['', 'valueQuantity', '[IU]/mL'],
        '22244-8' => ['eHealth/LOINC/LL2451-4', 'valueCodeableConcept', ''],
        '30325-5' => ['eHealth/LOINC/LL2451-4', 'valueCodeableConcept', ''],
        '7853-5' => ['', 'valueQuantity', '[IU]/mL'],
        '13238-1' => ['', 'valueQuantity', '[IU]/mL'],
        '49178-7' => ['eHealth/LOINC/LL2451-4', 'valueCodeableConcept', ''],
        '8039-0' => ['', 'valueQuantity', '[IU]/mL'],
        '22580-5' => ['eHealth/LOINC/LL2451-4', 'valueCodeableConcept', ''],
        '94819-0' => ['', 'valueQuantity', '{copies}/mL'],
        '94309-2' => ['eHealth/LOINC/LL3250-9', 'valueCodeableConcept', ''],
        '2947-0' => ['', 'valueQuantity', 'mmol/L'],
        '6298-4' => ['', 'valueQuantity', 'mmol/L'],
        '1996-8' => ['', 'valueQuantity', 'mmol/L'],
        '2069-3' => ['', 'valueQuantity', 'mmol/L'],
        '3040-3' => ['', 'valueQuantity', 'U/L'],
        '15074-8' => ['', 'valueQuantity', 'mmol/L'],
        '2885-2' => ['', 'valueQuantity', 'g/dL'],
        '1751-7' => ['', 'valueQuantity', 'g/dL'],
        '6768-6' => ['', 'valueQuantity', 'U/L'],
        '14631-6' => ['', 'valueQuantity', 'umol/L'],
        '14629-0' => ['', 'valueQuantity', 'umol/L'],
        '1798-8' => ['', 'valueQuantity', 'U/L'],
        '1742-6' => ['', 'valueQuantity', 'U/L'],
        '1920-8' => ['', 'valueQuantity', 'U/L'],
        '59826-8' => ['', 'valueQuantity', 'umol/L'],
        '72903-8' => ['', 'valueQuantity', 'umol/L'],
        '32673-6' => ['', 'valueQuantity', 'U/L'],
        '2157-6' => ['', 'valueQuantity', 'U/L'],
        '42757-5' => ['', 'valueQuantity', 'ng/mL'],
        '2324-2' => ['', 'valueQuantity', 'U/L'],
        '1988-5' => ['', 'valueQuantity', 'mg/L'],
        '14804-9' => ['', 'valueQuantity', 'U/L'],
        '14805-6' => ['', 'valueQuantity', 'U/L'],
        '2524-7' => ['', 'valueQuantity', 'mmol/L'],
        '33959-8' => ['', 'valueQuantity', 'ng/mL'],
        '33762-6' => ['', 'valueQuantity', 'pg/mL'],
        '48664-7' => ['', 'valueQuantity', 'g/L'], // деактивовано
        '5902-2' => ['', 'valueQuantity', 's'],
        '6302-4' => ['', 'valueQuantity', '%'],
        '34714-6' => ['', 'valueQuantity', '{INR}'],
        '3173-2' => ['', 'valueQuantity', 's'],
        '27811-9' => ['', 'valueQuantity', '%'],
        '11558-4' => ['', 'valueQuantity', '[pH]'],
        '11557-6' => ['', 'valueQuantity', 'mm[Hg]'],
        '11556-8' => ['', 'valueQuantity', 'mm[Hg]'],
        '1959-6' => ['', 'valueQuantity', 'mmol/L'],
        '29590-7' => ['', 'valueQuantity', 'pg/mL'],
        '30246-3' => ['eHealth/LOINC/LL2451-4', 'valueCodeableConcept', ''],
        '14647-2' => ['', 'valueQuantity', 'mmol/L'],
        'PPS' => ['0-100', 'valueQuantity', '%'],
        '74200-7' => ['', 'valueQuantity', 'day'],
        '87238-2' => ['', 'valueString', ''],
        '96761-2' => ['0-100', 'valueQuantity', 'ScoreOf']
    ],

    // https://e-health-ua.atlassian.net/wiki/spaces/EH/pages/17088643146/Configurations+for+Healthcare+services#Healthcare-services-configurable-parameters
    'healthcare_service_primary_care_categories' => ['MSP'],
    'healthcare_service_outpatient_categories' => ['MSP', 'PHARMACY_DRUGS'],
    'healthcare_service_emergency_categories' => ['MSP'],
    'healthcare_service_pharmacy_categories' => ['PHARMACY', 'PHARMACY_DRUGS'],

    // https://e-health-ua.atlassian.net/wiki/spaces/EH/pages/17088643146/Configurations+for+Healthcare+services#Allowed-providing-conditions-for-each-legal-entity-type
    'legal_entity_primary_care_providing_conditions' => ['OUTPATIENT'],
    'legal_entity_outpatient_providing_conditions' => ['INPATIENT', 'OUTPATIENT', 'FIELD'],
    'legal_entity_emergency_providing_conditions' => ['FIELD'],

    // https://e-health-ua.atlassian.net/wiki/spaces/EH/pages/18504778043/NEW+Equipment+dictionaries+and+configurable+parameters+OMB-126
    'equipment_types_with_required_serial_number' => ['Z1203010502'],

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

    // https://e-health-ua.atlassian.net/wiki/spaces/EH/pages/583402009/Medical+Events+Dictionaries+and+configurations#PSYCHIATRY_ICPC2_DIAGNOSES_EVIDENCE_CHECK
    'psychiatry_icpc2_diagnoses_evidence_check' => [
        'P70', 'P71', 'P72', 'P73', 'P74', 'P75', 'P76', 'P78', 'P79', 'P80', 'P81', 'P82', 'P85', 'P86', 'P98', 'P99'
    ],

    // https://e-health-ua.atlassian.net/wiki/spaces/EH/pages/583402009/Medical+Events+Dictionaries+and+configurations#ICD10_AM_%3CSPECIALITY_TYPE%3E_SPECIALITY_CONDITIONS_ALLOWED
    'icd10am_speciality_conditions_allowed' => [
        ...array_fill_keys(['PSYCHIATRY', 'PEDIATRIC_PSYCHIATRY'], [
            'F00.0', 'F00.1', 'F00.2', 'F00.9', 'F01.0', 'F01.1', 'F01.2', 'F01.3', 'F01.8', 'F01.9', 'F02.0', 'F02.1' ,
            'F02.2', 'F02.3', 'F02.4', 'F02.8', 'F03', 'F04.00', 'F04.01', 'F04.02', 'F04.03', 'F04.9', 'F05.0', 'F05.1',
            'F05.8', 'F05.9', 'F06.0', 'F06.1', 'F06.2', 'F06.30', 'F06.31', 'F06.32', 'F06.33', 'F06.34', 'F06.39',
            'F06.4' , 'F06.5', 'F06.6', 'F06.7', 'F06.8', 'F06.9', 'F07.0', 'F07.1', 'F07.2', 'F07.8', 'F07.9', 'F09',
            'F10.0', 'F10.1' , 'F10.2', 'F10.3', 'F10.4', 'F10.5', 'F10.6', 'F10.7', 'F10.8', 'F10.9', 'F11.0', 'F11.1',
            'F11.2', 'F11.3', 'F11.4' , 'F11.5', 'F11.6', 'F11.7', 'F11.8', 'F11.9', 'F12.0', 'F12.1', 'F12.2', 'F12.3',
            'F12.4', 'F12.5', 'F12.6', 'F12.7' , 'F12.8', 'F12.9', 'F13.00', 'F13.01', 'F13.09', 'F13.10', 'F13.11',
            'F13.19', 'F13.20', 'F13.21', 'F13.29', 'F13.30', 'F13.31', 'F13.39', 'F13.40', 'F13.41', 'F13.49', 'F13.50',
            'F13.51', 'F13.59', 'F13.60', 'F13.61', 'F13.69', 'F13.70', 'F13.71', 'F13.79', 'F13.80', 'F13.81', 'F13.89',
            'F13.90', 'F13.91', 'F13.99', 'F14.0', 'F14.1', 'F14.2', 'F14.3', 'F14.4', 'F14.5', 'F14.6', 'F14.7',
            'F14.8', 'F14.9', 'F15.00', 'F15.01', 'F15.02', 'F15.09', 'F15.10', 'F15.11', 'F15.12', 'F15.19', 'F15.20',
            'F15.21', 'F15.22', 'F15.29', 'F15.30', 'F15.31', 'F15.32', 'F15.39', 'F15.40', 'F15.41', 'F15.42', 'F15.49',
            'F15.50', 'F15.51', 'F15.52', 'F15.59', 'F15.60', 'F15.61', 'F15.62', 'F15.69', 'F15.70', 'F15.71', 'F15.72',
            'F15.79', 'F15.80', 'F15.81', 'F15.82', 'F15.89', 'F15.90', 'F15.91', 'F15.92', 'F15.99', 'F16.00', 'F16.01',
            'F16.09', 'F16.10', 'F16.11', 'F16.19', 'F16.20', 'F16.21', 'F16.29', 'F16.30', 'F16.31', 'F16.39', 'F16.40',
            'F16.41', 'F16.49', 'F16.50', 'F16.51', 'F16.59', 'F16.60', 'F16.61', 'F16.69', 'F16.70', 'F16.71', 'F16.79',
            'F16.80', 'F16.81', 'F16.89', 'F16.90', 'F16.91', 'F16.99', 'F17.0', 'F17.1', 'F17.2', 'F17.3', 'F17.4',
            'F17.5', 'F17.6', 'F17.7', 'F17.8', 'F17.9', 'F18.0', 'F18.1', 'F18.2', 'F18.3', 'F18.4', 'F18.5', 'F18.6',
            'F18.7', 'F18.8', 'F18.9', 'F19.0', 'F19.1', 'F19.2', 'F19.3', 'F19.4', 'F19.5', 'F19.6', 'F19.7', 'F19.8',
            'F19.9', 'F20.0', 'F20.1', 'F20.2', 'F20.3', 'F20.4', 'F20.5', 'F20.6', 'F20.8', 'F20.9', 'F21', 'F22.0',
            'F22.8', 'F22.9', 'F23.00', 'F23.01', 'F23.10', 'F23.11', 'F23.20', 'F23.21', 'F23.30', 'F23.31', 'F23.80',
            'F23.81', 'F23.90', 'F23.91', 'F24', 'F25.0', 'F25.1', 'F25.2', 'F25.8', 'F25.9', 'F28', 'F29', 'F30.0',
            'F30.1', 'F30.2', 'F30.8', 'F30.9', 'F31.0', 'F31.1', 'F31.2', 'F31.3', 'F31.4', 'F31.5', 'F31.6', 'F31.7',
            'F31.8', 'F31.9', 'F32.00', 'F32.01', 'F32.10', 'F32.11', 'F32.20', 'F32.21', 'F32.30', 'F32.31', 'F32.80',
            'F32.81', 'F32.90', 'F32.91', 'F33.0', 'F33.1', 'F33.2', 'F33.3', 'F33.4', 'F33.8', 'F33.9', 'F34.0',
            'F34.1', 'F34.8', 'F34.9', 'F38.0', 'F38.1', 'F38.8', 'F39', 'F40.00', 'F40.01', 'F40.1', 'F40.2', 'F40.8',
            'F40.9', 'F41.0', 'F41.1', 'F41.2', 'F41.3', 'F41.8', 'F41.9', 'F42.0', 'F42.1', 'F42.2', 'F42.8', 'F42.9',
            'F43.0', 'F43.1', 'F43.2', 'F43.8', 'F43.9', 'F44.0', 'F44.1', 'F44.2', 'F44.3', 'F44.4', 'F44.5', 'F44.6',
            'F44.7', 'F44.80', 'F44.81', 'F44.82', 'F44.88', 'F44.9', 'F45.0', 'F45.1', 'F45.2', 'F45.30', 'F45.31',
            'F45.32', 'F45.33', 'F45.34', 'F45.35', 'F45.38', 'F45.39', 'F45.4', 'F45.8', 'F45.9', 'F48.0', 'F48.1',
            'F48.8', 'F48.9', 'F50.0', 'F50.1', 'F50.2', 'F50.3', 'F50.4', 'F50.5', 'F50.8', 'F50.9', 'F51.0', 'F51.1',
            'F51.2', 'F51.3', 'F51.4', 'F51.5', 'F51.8', 'F51.9', 'F52.0', 'F52.1', 'F52.2', 'F52.3', 'F52.4', 'F52.5',
            'F52.6', 'F52.7', 'F52.8', 'F52.9', 'F53.0', 'F53.1', 'F53.8', 'F53.9', 'F54', 'F55.0', 'F55.1', 'F55.2',
            'F55.3', 'F55.4', 'F55.5', 'F55.6', 'F55.8', 'F55.9', 'F59', 'F60.0', 'F60.1', 'F60.2', 'F60.30', 'F60.31',
            'F60.4', 'F60.5', 'F60.6', 'F60.7', 'F60.8', 'F60.9', 'F61', 'F62.0', 'F62.1', 'F62.8', 'F62.9', 'F63.0',
            'F63.1', 'F63.2', 'F63.3', 'F63.8', 'F63.9', 'F64.0', 'F64.1', 'F64.2', 'F64.8', 'F64.9', 'F65.0', 'F65.1',
            'F65.2', 'F65.3', 'F65.4', 'F65.5', 'F65.6', 'F65.8', 'F65.9', 'F66.0', 'F66.1', 'F66.2', 'F66.8', 'F66.9',
            'F68.0', 'F68.1', 'F68.8', 'F69', 'F70.0', 'F70.1', 'F70.8', 'F70.9', 'F71.0', 'F71.1', 'F71.8', 'F71.9',
            'F72.0', 'F72.1', 'F72.8', 'F72.9', 'F73.0', 'F73.1', 'F73.8', 'F73.9', 'F78.0', 'F78.1', 'F78.8', 'F78.9',
            'F79.0', 'F79.1', 'F79.8', 'F79.9', 'F80.0', 'F80.1', 'F80.2', 'F80.3', 'F80.8', 'F80.9', 'F81.0', 'F81.1',
            'F81.2', 'F81.3', 'F81.8', 'F81.9', 'F82', 'F83', 'F84.0', 'F84.1', 'F84.2', 'F84.3', 'F84.4', 'F84.5',
            'F84.8', 'F84.9', 'F88', 'F89', 'F90.0', 'F90.1', 'F90.8', 'F90.9', 'F91.0', 'F91.1', 'F91.2', 'F91.3',
            'F91.8', 'F91.9', 'F92.0', 'F92.8', 'F92.9', 'F93.0', 'F93.1', 'F93.2', 'F93.3', 'F93.8', 'F93.9', 'F94.0',
            'F94.1', 'F94.2', 'F94.8', 'F94.9', 'F95.0', 'F95.1', 'F95.2', 'F95.8', 'F95.9' , 'F98.0', 'F98.1',
            'F98.2', 'F98.3', 'F98.4', 'F98.5', 'F98.6', 'F98.8', 'F98.9', 'F99'
        ]),
        'NARCOLOGY' => [
            'F10.0', 'F10.1', 'F10.2', 'F10.3', 'F10.4', 'F10.5', 'F10.6', 'F10.7', 'F10.8', 'F10.9', 'F11.0', 'F11.1',
            'F11.2', 'F11.3', 'F11.4', 'F11.5', 'F11.6', 'F11.7', 'F11.8', 'F11.9', 'F12.0', 'F12.1', 'F12.2', 'F12.3',
            'F12.4', 'F12.5', 'F12.6', 'F12.7', 'F12.8', 'F12.9', 'F13.00', 'F13.01', 'F13.09', 'F13.10', 'F13.11',
            'F13.19', 'F13.20', 'F13.21', 'F13.29', 'F13.30', 'F13.31', 'F13.39', 'F13.40', 'F13.41', 'F13.49', 'F13.50',
            'F13.51', 'F13.59', 'F13.60', 'F13.61', 'F13.69', 'F13.70', 'F13.71', 'F13.79', 'F13.80', 'F13.81', 'F13.89',
            'F13.90', 'F13.91', 'F13.99', 'F14.0', 'F14.1', 'F14.2', 'F14.3', 'F14.4', 'F14.5', 'F14.6', 'F14.7',
            'F14.8', 'F14.9', 'F15.00', 'F15.01', 'F15.02', 'F15.09', 'F15.10', 'F15.11', 'F15.12', 'F15.19', 'F15.20',
            'F15.21', 'F15.22', 'F15.29', 'F15.30', 'F15.31', 'F15.32', 'F15.39', 'F15.40', 'F15.41', 'F15.42', 'F15.49',
            'F15.50', 'F15.51', 'F15.52', 'F15.59', 'F15.60', 'F15.61', 'F15.62', 'F15.69', 'F15.70', 'F15.71', 'F15.72',
            'F15.79', 'F15.80', 'F15.81', 'F15.82', 'F15.89', 'F15.90', 'F15.91', 'F15.92', 'F15.99', 'F16.00', 'F16.01',
            'F16.09', 'F16.10', 'F16.11', 'F16.19', 'F16.20', 'F16.21', 'F16.29', 'F16.30', 'F16.31', 'F16.39', 'F16.40',
            'F16.41', 'F16.49', 'F16.50', 'F16.51', 'F16.59', 'F16.60', 'F16.61', 'F16.69', 'F16.70', 'F16.71', 'F16.79',
            'F16.80', 'F16.81', 'F16.89', 'F16.90', 'F16.91', 'F16.99', 'F17.0', 'F17.1', 'F17.2', 'F17.3', 'F17.4',
            'F17.5', 'F17.6', 'F17.7', 'F17.8', 'F17.9', 'F18.0', 'F18.1', 'F18.2', 'F18.3', 'F18.4', 'F18.5', 'F18.6',
            'F18.7', 'F18.8', 'F18.9', 'F19.0', 'F19.1', 'F19.2', 'F19.3', 'F19.4', 'F19.5', 'F19.6', 'F19.7', 'F19.8',
            'F19.9'
        ],
    ],

    // https://e-health-ua.atlassian.net/wiki/spaces/EH/pages/583402009/Medical+Events+Dictionaries+and+configurations#%3Csystem%3E_ASSISTANT_EMPLOYEE_CONDITIONS_ALLOWED
    // https://e-health-ua.atlassian.net/wiki/spaces/EH/pages/583402009/Medical+Events+Dictionaries+and+configurations#%3Csystem%3E_MED_COORDINATOR_EMPLOYEE_CONDITIONS_ALLOWED
    'employee_type_conditions_allowed' => [
        'ASSISTANT' => [
            'eHealth/ICD10_AM/condition_codes' => [
                'Z00.0', 'Z00.1', 'Z00.2', 'Z00.3', 'Z00.4', 'Z00.5', 'Z00.6', 'Z00.8', 'Z01.3', 'Z02.0', 'Z02.1',
                'Z02.2', 'Z02.3', 'Z02.4', 'Z02.5', 'Z02.6', 'Z02.7', 'Z02.8', 'Z02.9', 'Z10.0', 'Z10.1', 'Z10.3',
                'Z10.8', 'Z71.8', 'Z71.9', 'Z72.0', 'Z72.1', 'Z72.2', 'Z72.3', 'Z72.4', 'Z72.8', 'Z72.9', 'Z73.8',
                'Z73.9', 'Z74.0', 'Z74.1', 'Z74.2', 'Z74.3', 'Z75.0', 'Z75.1', 'Z75.2', 'Z75.3', 'Z75.4', 'Z75.5',
                'Z75.8', 'Z75.9', 'Z75.10', 'Z75.11', 'Z75.12', 'Z75.13', 'Z75.14', 'Z75.18', 'Z75.19', 'Z75.40',
                'Z75.41', 'Z75.49', 'Z76.0', 'Z76.1', 'Z76.2', 'Z76.3', 'Z76.4', 'Z76.5', 'Z76.8', 'Z76.9', 'Z76.21',
                'Z76.22', 'Z28.0', 'Z28.1', 'Z28.2', 'Z28.8', 'Z28.9', 'Z29.0', 'Z29.1', 'Z29.2', 'Z29.8', 'Z29.9',
                'Z25.8'
            ],
            'eHealth/ICPC2/condition_codes' => [
                'A98', 'A13'
            ],
        ],
        'MED_COORDINATOR' => [
            'eHealth/ICD10_AM/condition_codes' => [
                'Z94.0', 'Z94.1', 'Z94.2', 'Z94.3', 'Z94.4', 'Z94.8', 'Z94.9', 'Z00.5', 'Z53.8', 'Z76.82', 'Z52.1', 'Z52.2',
                'Z52.3', 'Z52.4', 'Z52.5', 'Z52.6', 'Z52.7', 'Z52.8', 'Z52.9'
            ],
        ],
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
    ],
];
