<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Conditions Language Lines
    |--------------------------------------------------------------------------
    |
    | The following language lines are for messages related to conditions
    | (diagnoses/states), e.g., condition form labels, evidence blocks,
    | record listings, etc.
    |
    */

    'label' => 'Стан',
    'plural' => 'Стани',
    'search' => 'Пошук станів',
    'condition_or_diagnosis' => 'Стан або діагноз',
    'icd-10' => 'МКХ-10',
    'clinical_status' => 'Клінічний статус',
    'entry_date' => 'Дата внесення',
    'severity' => 'Ступінь тяжкості',
    'severity_of_the_condition' => 'Ступінь тяжкості стану',
    'primary_source' => 'Первинне джерело',
    'choose_coding_system' => 'Оберіть систему кодування',
    'add_icd10_code' => ' Додати код за МКХ-10',
    'add_icpc2_code' => ' Додати код за ICPC-2',
    'add_diagnose' => 'Додати діагноз',
    'new_diagnose_state' => 'Новий діагноз/стан',
    'edit_diagnose_state' => 'Редагувати діагноз/стан',
    'new_primary_diagnose' => "Ви вказали новий основний діагноз.<br> Підтвердження дії змінить основний діагноз епізоду медичної допомоги!",
    'add_body_part' => ' Додати частину тіла',
    'evidence' => 'Докази',
    'evidence_observations' => 'Спостереження',
    'filter_onset_date_range' => 'Дата початку від - до',
    'onset_after_encounter_end' => 'Дата та час початку діагнозу не можуть бути пізнішими за дату та час завершення взаємодії.',
    'asserted_outside_encounter_period' => 'Дата та час внесення діагнозу мають бути в межах періоду взаємодії.',

    // Holds both the clinical and the verification status of a condition
    'status' => [
        'active' => 'Діючий',
        'finished' => 'Завершений',
        'recurrence' => 'рецидив',
        'remission' => 'ремісія',
        'resolved' => 'вилікуваний',
        'confirmed' => 'заключний',
        'differential' => 'диференціальний',
        'provisional' => 'попередній',
        'refuted' => 'спростований',
        'entered_in_error' => 'Внесений помилково'
    ],

    'messages' => [
        'synced_successfully' => 'Стани успішно синхронізовані',
        'first_page_synced_successfully' => 'Перша сторінка станів синхронізована, решта обробляється у фоні',
        'sync_already_running' => 'Синхронізація станів вже запущена. Будь ласка, зачекайте її завершення.',
        'sync_resume_started' => 'Відновлення попередньої синхронізації станів розпочато',
        'sync_background_dispatch_error' => 'Помилка запуску фонової синхронізації станів'
    ],

    // Custom messages for validation rules
    'validation' => [
        'code_system_class_forbidden' => "Для класу взаємодії 'Амбулаторна медична допомога' та 'Стаціонарна медична допомога' дозволена лише система eHealth/ICD10_AM/condition_codes",
        'verification_status_not_in' => 'Діагноз, який додано до взаємодії, не може бути позначений внесеним помилково',
        'psychiatry_evidence_required' => 'Для коду діагнозу :code необхідно вказати стан як доказ',
        'psychiatry_evidence_code_forbidden' => 'Стан не може бути використаний як доказ для коду діагнозу :code',
        'employee_type_code_forbidden' => 'Встановлювач діагнозу не має необхідного типу працівника для встановлення коду :code.',
        'speciality_condition_code_forbidden' => 'Встановлювач діагнозу не має необхідної спеціальності для встановлення коду :code.',
        'asserter_employee_not_found' => 'Працівника, вказаного як встановлювача діагнозу, не знайдено.',
        'asserter_employee_invalid_type' => 'Тип працівника не дозволений як встановлювач діагнозу.',
        'asserter_employee_not_participant' => 'Працівник, вказаний як встановлювач діагнозу, має бути учасником взаємодії.'
    ],

    // Field names for :attribute in validation messages
    'attributes' => [
        'primarySource' => 'первинне джерело діагнозу',
        'reportOriginCode' => 'джерело інформації діагнозу',
        'codeCode' => 'джерело інформації діагнозу',
        'codeSystem' => 'код стану діагнозу',
        'clinicalStatus' => 'клінічний статус діагнозу',
        'verificationStatus' => 'статус верифікації діагнозу',
        'severityCode' => 'ступінь тяжкості стану діагнозу',
        'stageCode' => 'стадія стану діагнозу',
        'bodySites.*.code' => 'частина тіла діагнозу',
        'onsetDate' => 'дата початку діагнозу',
        'onsetTime' => 'час початку діагнозу',
        'assertedDate' => 'дата внесення діагнозу',
        'assertedTime' => 'час внесення діагнозу',
        'asserterText' => 'коментар встановлювача діагнозу',
        'evidenceCodes.*.code' => 'стани доказів діагнозу',
        'evidenceDetails.*.id' => 'доказ діагнозу',
        'evidenceDetails.*.type' => 'тип доказу діагнозу'
    ]
];
