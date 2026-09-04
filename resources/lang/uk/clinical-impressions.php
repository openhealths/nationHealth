<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Clinical Impressions Language Lines
    |--------------------------------------------------------------------------
    |
    | The following language lines are for messages related to clinical
    | impressions, e.g., form section headings, record listings, filters, etc.
    |
    */

    'label' => 'Клінічна оцінка',
    'plural' => 'Клінічні оцінки',
    'search' => 'Пошук клінічних оцінок',
    'conclusion' => 'Заключення по оцінці',
    'set_of_rule_engines' => 'Набір механізмів правил',
    'previous' => 'Попередня клінічна оцінка',
    'appropriate_patient_assessment' => 'Відповідна оцінка стану пацієнта',
    'what_was_identified' => 'Що було ідентифіковано',
    'supporting_medical_information' => 'Підтверджуючі медичні дані',
    'employee_who_created' => 'Працівник, який створив',
    'reception_start_date_and_time' => 'Дата та час початку прийому',
    'reception_end_date_and_time' => 'Дата та час завершення прийому',
    'filter_effective_date_range' => 'Дата ефективності від - до',

    'status' => [
        'completed' => 'Завершений',
        'entered_in_error' => 'Внесений помилково'
    ],

    // Outcomes reported to the user after an action
    'messages' => [
        'synced_successfully' => 'Клінічні оцінки успішно синхронізовані',
        'first_page_synced_successfully' => 'Перша сторінка клінічних оцінок синхронізована, решта обробляється у фоні',
        'sync_already_running' => 'Синхронізація клінічних оцінок вже запущена. Будь ласка, зачекайте її завершення.',
        'sync_resume_started' => 'Відновлення попередньої синхронізації клінічних оцінок розпочато',
        'sync_background_dispatch_error' => 'Помилка запуску фонової синхронізації клінічних оцінок'
    ],

    // Field names for :attribute in validation messages
    'attributes' => [
        'codeCode' => 'код клінічної оцінки',
        'description' => 'заключення клінічної оцінки',
        'effectivePeriodStartDate' => 'дата початку клінічної оцінки',
        'effectivePeriodStartTime' => 'час початку клінічної оцінки',
        'effectivePeriodEndDate' => 'дата завершення клінічної оцінки',
        'effectivePeriodEndTime' => 'час завершення клінічної оцінки',
        'note' => 'опис клінічної оцінки',
        'previous.*.id' => 'попередня клінічна оцінка',
        'problems.*.id' => 'відповідна оцінка стану пацієнта',
        'findings.*.id' => 'що було ідентифіковано',
        'findings.*.type' => 'тип того, що було ідентифіковано',
        'supportingInfo.*.uuid' => 'допоміжна медична інформація',
        'supportingInfo.*.type' => 'тип допоміжної медичної інформації'
    ]
];
