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
    'set_of_rule_engines' => 'Набір механізмів правил',
    'filter_effective_date_range' => 'Дата ефективності від - до',
    'assessor' => 'Працівник, який створив',
    'code' => 'Код',
    'description' => 'Опис',
    'effective_period_start' => 'Дата та час початку прийому',
    'effective_period_end' => 'Дата та час завершення прийому',
    'findings' => 'Що було ідентифіковано',
    'findings_basis' => 'Обґрунтування знахідки',
    'note' => 'Коментар',
    'previous' => 'Попередня клінічна оцінка',
    'problems' => 'Відповідна оцінка стану пацієнта',
    'summary' => 'Підсумок',
    'supporting_info' => 'Підтверджуючі медичні дані',
    'episode_id' => 'ID епізоду',
    'inserted_at' => 'Створено',

    'status' => [
        'completed' => 'Виконана',
        'entered_in_error' => 'Внесена помилково'
    ],

    // Outcomes reported to the user after an action
    'messages' => [
        'synced_successfully' => 'Клінічні оцінки успішно синхронізовані',
        'first_page_synced_successfully' => 'Перша сторінка клінічних оцінок синхронізована, решта обробляється у фоні',
        'sync_already_running' => 'Синхронізація клінічних оцінок вже запущена. Будь ласка, зачекайте її завершення.',
        'sync_resume_started' => 'Відновлення попередньої синхронізації клінічних оцінок розпочато',
        'sync_background_dispatch_error' => 'Помилка запуску фонової синхронізації клінічних оцінок'
    ],

    // Number of the record every :attribute of a validation message carries
    'position' => 'клінічна оцінка №:position',

    // Field names for :attribute in validation messages
    'attributes' => [
        'codeCode' => 'код клінічної оцінки',
        'assessorEmployeeId' => 'медичний працівник, який провів клінічну оцінку',
        'description' => 'опис клінічної оцінки',
        'effectivePeriodStartDate' => 'дата початку клінічної оцінки',
        'effectivePeriodStartTime' => 'час початку клінічної оцінки',
        'effectivePeriodEndDate' => 'дата завершення клінічної оцінки',
        'effectivePeriodEndTime' => 'час завершення клінічної оцінки',
        'note' => 'коментар клінічної оцінки',
        'summary' => 'підсумок клінічної оцінки',
        'previous.*.id' => 'попередня клінічна оцінка',
        'problems.*.id' => 'відповідна оцінка стану пацієнта',
        'findings.*.id' => 'що було ідентифіковано',
        'findings.*.type' => 'тип того, що було ідентифіковано',
        'findings.*.basis' => 'обґрунтування знахідки',
        'supportingInfo.*.uuid' => 'допоміжна медична інформація',
        'supportingInfo.*.type' => 'тип допоміжної медичної інформації'
    ]
];
