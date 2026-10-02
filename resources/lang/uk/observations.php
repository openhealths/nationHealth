<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Observations Language Lines
    |--------------------------------------------------------------------------
    |
    | The following language lines are for messages related to observations,
    | e.g., observation form labels, component labels, record listings, etc.
    |
    */

    'label' => 'Спостереження',
    'plural' => 'Спостереження',
    'medical_label' => 'Медичне спостереження',
    'category_and_code' => 'Категорія та код',
    'category' => 'Категорія',
    'code' => 'Код',
    'code_system' => 'Тип',
    'coding_system' => 'Система кодувань',
    'value' => 'Значення',
    'value_date' => 'Дата',
    'components' => 'Компоненти',
    'extent_or_magnitude_of_impairment' => 'Обсяг або величина порушення',
    'nature_of_change_in_body_structure' => 'Природа змін у структурах організму',
    'anatomical_localization' => 'Анатомічна локалізація',
    'body_site' => 'Частина тіла',
    'performance' => 'Виконання',
    'capacity' => 'Здатність',
    'barrier_or_facilitator' => 'Величина та вид впливу',
    'interpretation' => 'Інтерпретація',
    'method' => 'Метод спостереження',
    'doctor' => 'Лікар',
    'primary_source' => 'Джерело',
    'interpretation_of_observation' => 'Інтерпретація спостереження',
    'date_and_time_of_receiving_the_indicators' => 'Дата та час отримання показників',
    'getting_indicators' => 'Отримання показників',
    'result_received_at' => 'Дата та час отримання результату',
    'issued' => 'Дата',
    'inserted_at' => 'Створено',
    'updated_at' => 'Оновлено',
    'effective_label' => 'Коли проводилося спостереження',
    'effective_date_time' => 'Точна дата і час',
    'effective_period' => 'Період',
    'effective_period_start' => 'Час початку',
    'effective_period_end' => 'Час завершення',
    'comment' => 'Коментар',
    'result' => 'Результат',
    'reference_range' => 'Референтний діапазон',
    'component' => 'Компонент',
    'no_components' => 'Немає компонентів',
    'general_info' => 'Загальна інформація',
    'code_and_name' => 'Код та назва',
    'status_label' => 'Статус',
    'method_label' => 'Метод',
    'body_site_label' => 'Ділянка тіла',
    'device' => 'Медичне обладнання',
    'effective_date_label' => 'Дата/період проведення',
    'performer' => 'Виконавець',
    'managing_organization' => 'Заклад, де створено спостереження',
    'diagnostic_report' => 'Діагностичний звіт',
    'specimen' => 'Зразок біоматеріалу',
    'context' => 'Взаємодія',
    'inserted_at_label' => 'Дата та час внесення в Систему',
    'updated_at_label' => 'Дата та час оновлення запису в Системі',
    'position' => 'спостереження №:position',
    'specimen_id' => 'ID зразка',
    'reaction_on' => 'Реакція на вакцинацію',
    'reaction_on_add' => 'Додати вакцинацію',
    'search_medical_records' => 'Пошук медичних записів',
    'records_search' => 'Пошук',
    'preperson_alert_title' => 'Обов’язкові спостереження для неідентифікованого пацієнта',
    'preperson_alert_text' => 'Стать, зріст тіла, вага тіла',

    'status' => [
        'valid' => 'Дійсний',
        'entered_in_error' => 'Внесений помилково'
    ],

    'messages' => [
        'synced_successfully' => 'Спостереження успішно синхронізовані',
        'first_page_synced_successfully' => 'Перша сторінка спостережень синхронізована, решта обробляється у фоні',
        'sync_already_running' => 'Синхронізація спостережень вже запущена. Будь ласка, зачекайте її завершення.',
        'sync_resume_started' => 'Відновлення попередньої синхронізації спостережень розпочато',
        'sync_background_dispatch_error' => 'Помилка запуску фонової синхронізації спостережень'
    ],

    // Custom messages for validation rules
    'validation' => [
        'performer_employee_not_found' => 'Працівника, вказаного як виконавця спостереження, не знайдено.',
        'performer_employee_invalid_type' => 'Тип працівника не дозволений як виконавець спостереження.',
        'performer_not_participant' => 'Виконавець спостереження має бути учасником взаємодії.'
    ],

    // Field names for :attribute in validation messages
    'attributes' => [
        'primarySource' => 'джерело інформації спостереження',
        'performerEmployeeId' => 'виконавець спостереження',
        'reportOriginCode' => 'посилання на джерело спостереження',
        'reportOriginText' => 'опис джерела спостереження',
        'categorySystem' => 'система кодування спостереження',
        'categoryCode' => 'категорія спостереження',
        'codeSystem' => 'система кодування спостереження',
        'codeCode' => 'код спостереження',
        'effectiveDate' => 'дата отримання показників спостереження',
        'effectiveTime' => 'час отримання показників спостереження',
        'issuedDate' => 'дата внесення спостереження',
        'issuedTime' => 'час внесення спостереження',
        'effectivePeriodStartDate' => 'дата початку спостереження',
        'effectivePeriodStartTime' => 'час початку спостереження',
        'effectivePeriodEndDate' => 'дата завершення спостереження',
        'effectivePeriodEndTime' => 'час завершення спостереження',
        'interpretationCode' => 'інтерпретація спостереження',
        'bodySiteCode' => 'частина тіла спостереження',
        'deviceId' => 'обладнання спостереження',
        'methodCode' => 'метод спостереження',
        'reactionOn' => 'реакція на вакцинацію',
        'dictionaryName' => 'словник спостереження',
        'comment' => 'коментар спостереження',
        'components' => 'компоненти спостереження',
        'components.*.codeCode' => 'Величина та вид впливу спостереження',
        'components.*.codeSystem' => 'система кодування компоненту спостереження',
        'components.*.valueCode' => 'значення компоненту спостереження',
        'components.*.valueSystem' => 'система кодування значення компоненту спостереження',
        'components.*.interpretationCode' => 'інтерпретація спостереження',
        'valueQuantityValue' => 'значення спостереження',
        'valueQuantityComparator' => 'порівняння значення спостереження',
        'valueQuantityUnit' => 'одиниця виміру значення',
        'valueQuantitySystem' => 'словник одиниці виміру значення',
        'valueQuantityCode' => 'код одиниці виміру значення',
        'valueCodeableConcept' => 'значення спостереження',
        'valueString' => 'значення спостереження',
        'valueBoolean' => 'значення спостереження',
        'valueDate' => 'значення (дата) спостереження',
        'valueTime' => 'значення (час) спостереження',
        'valueSampledDataData' => 'дані вибірки спостереження',
        'valueSampledDataOrigin' => 'початкове значення вибірки спостереження',
        'valueSampledDataPeriod' => 'період вибірки спостереження',
        'valueSampledDataFactor' => 'коефіцієнт вибірки спостереження',
        'valueSampledDataLowerLimit' => 'нижня межа вибірки спостереження',
        'valueSampledDataUpperLimit' => 'верхня межа вибірки спостереження',
        'valueSampledDataDimensions' => 'кількість вимірів вибірки спостереження',
        'valueRange' => 'діапазон значення спостереження',
        'valueRange.low' => 'нижня межа діапазону спостереження',
        'valueRange.high' => 'верхня межа діапазону спостереження',
        'valueRatio' => 'співвідношення значення спостереження',
        'valueRatio.numerator' => 'чисельник співвідношення спостереження',
        'valueRatio.denominator' => 'знаменник співвідношення спостереження'
    ]
];
