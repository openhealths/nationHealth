<?php

declare(strict_types=1);

// Config params Person Documents
// https://e-health-ua.atlassian.net/wiki/spaces/ESOZ/pages/20236599297/DRAFT+Config+params+Person+Documents+ENT-053
// https://e-health-ua.atlassian.net/wiki/spaces/ESOZ/pages/19725978326/RCC_FOREIGN+Foreigners+registration+Charts+Configuration+Parameters_EN
return [
    'declaration_no_self_auth_age_document_types' => [
        'BIRTH_CERTIFICATE', 'BIRTH_CERTIFICATE_FOREIGN', 'FOREIGN_PASSPORT'
    ],
    'declaration_self_auth_age_document_types' => [
        'PASSPORT', 'NATIONAL_ID', 'TEMPORARY_PASSPORT', 'PERMANENT_RESIDENCE_PERMIT', 'REFUGEE_CERTIFICATE',
        'COMPLEMENTARY_PROTECTION_CERTIFICATE', 'FOREIGN_PASSPORT'
    ],
    'document_types_issuing_country_not_ua' => [
        'FOREIGN_PASSPORT', 'FOREIGN_DOCUMENT_OTHER', 'BIRTH_CERTIFICATE_FOREIGN'
    ],
    'document_types_issuing_country_ua_only' => [
        'BIRTH_CERTIFICATE', 'COMPLEMENTARY_PROTECTION_CERTIFICATE', 'NATIONAL_ID', 'PASSPORT',
        'PERMANENT_RESIDENCE_PERMIT', 'REFUGEE_CERTIFICATE', 'TEMPORARY_PASSPORT', 'TEMPORARY_CERTIFICATE'
    ],
    'identity_document_types' => [
        'BIRTH_CERTIFICATE', 'BIRTH_CERTIFICATE_FOREIGN', 'COMPLEMENTARY_PROTECTION_CERTIFICATE', 'NATIONAL_ID',
        'PASSPORT', 'PERMANENT_RESIDENCE_PERMIT', 'REFUGEE_CERTIFICATE', 'TEMPORARY_CERTIFICATE', 'TEMPORARY_PASSPORT'
    ],
    'identity_document_types_foreign' => ['FOREIGN_PASSPORT', 'NO_CITIZENSHIP_CERTIFICATE', 'FOREIGN_DOCUMENT_OTHER'],
    'no_self_auth_age_document_types' => [
        'BIRTH_CERTIFICATE', 'BIRTH_CERTIFICATE_FOREIGN', 'FOREIGN_PASSPORT', 'FOREIGN_DOCUMENT_OTHER'
    ],
    'permanent_residence_permit' => [],
    // PERSON_DOCUMENTS_USE_SPECIFIC_EXPIRATION_DATE / PERSON_DOCUMENTS_SPECIFIC_EXPIRATION_DATE — when enabled,
    // a document expiration_date must be later than the specific date instead of just being in the future
    'specific_expiration_date' => null,
    'use_specific_expiration_date' => true,
    'person_legal_capacity_document_types' => [
        'DIVORCE_CERTIFICATE', 'MARRIAGE_CERTIFICATE', 'STATE_REGISTER_EXTRACT', 'COURT_DECISION_LEGAL_CAPACITY',
        'COURT_DECISION_DIVORCE', 'GUARDIANSHIP_DECISION_LEGAL_CAPACITY', 'LEGAL_CAPACITY_DOCUMENT'
    ],
    // https://e-health-ua.atlassian.net/wiki/spaces/ESOZ/pages/19725978326/RCC_FOREIGN+Foreigners+registration+Charts+Configuration+Parameters_EN#Charts-configuration-parameters
    'person_registration_document_types' => [
        'BIRTH_CERTIFICATE', 'BIRTH_CERTIFICATE_FOREIGN', 'COMPLEMENTARY_PROTECTION_CERTIFICATE', 'NATIONAL_ID',
        'PASSPORT', 'PERMANENT_RESIDENCE_PERMIT', 'REFUGEE_CERTIFICATE', 'TEMPORARY_CERTIFICATE', 'TEMPORARY_PASSPORT',
        'FOREIGN_PASSPORT', 'NO_CITIZENSHIP_CERTIFICATE', 'FOREIGN_DOCUMENT_OTHER'
    ],
    'self_auth_age_document_types' => [
        'BIRTH_CERTIFICATE', 'BIRTH_CERTIFICATE_FOREIGN', 'COMPLEMENTARY_PROTECTION_CERTIFICATE', 'NATIONAL_ID',
        'PASSPORT', 'PERMANENT_RESIDENCE_PERMIT', 'REFUGEE_CERTIFICATE', 'TEMPORARY_CERTIFICATE', 'TEMPORARY_PASSPORT',
        'FOREIGN_PASSPORT', 'FOREIGN_DOCUMENT_OTHER', 'NO_CITIZENSHIP_CERTIFICATE'
    ]
];
