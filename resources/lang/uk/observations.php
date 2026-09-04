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
    'plural' => 'Обстеження',
    'medical_label' => 'Медичне обстеження',
    'value' => 'Значення',
    'components' => 'Компоненти',
    'extent_or_magnitude_of_impairment' => 'Обсяг або величина порушення',
    'nature_of_change_in_body_structure' => 'Природа змін у структурах організму',
    'anatomical_localization' => 'Анатомічна локалізація',
    'performance' => 'Виконання',
    'capacity' => 'Здатність',
    'barrier_or_facilitator' => 'Величина та вид впливу',
    'interpretation' => 'Інтерпретація',
    'method' => 'Метод спостереження',
    'interpretation_of_observation' => 'Інтерпретація спостереження',
    'date_and_time_of_receiving_the_indicators' => 'Дата та час отримання показників',
    'getting_indicators' => 'Отримання показників',
    'preperson_alert_title' => 'Обов’язкові спостереження для неідентифікованого пацієнта',
    'preperson_alert_text' => 'Стать, зріст тіла, вага тіла',

    'status' => [
        'valid' => 'Дійсний',
        'entered_in_error' => 'Внесений помилково'
    ],

    'messages' => [
        'synced_successfully' => 'Обстеження успішно синхронізовані',
        'first_page_synced_successfully' => 'Перша сторінка обстежень синхронізована, решта обробляється у фоні',
        'sync_already_running' => 'Синхронізація обстежень вже запущена. Будь ласка, зачекайте її завершення.',
        'sync_resume_started' => 'Відновлення попередньої синхронізації обстежень розпочато',
        'sync_background_dispatch_error' => 'Помилка запуску фонової синхронізації обстежень'
    ],

    // Custom messages for validation rules
    'validation' => [
        'performer_employee_not_found' => 'Працівника, вказаного як виконавця обстеження, не знайдено.',
        'performer_employee_invalid_type' => 'Тип працівника не дозволений як виконавець обстеження.',
        'performer_not_participant' => 'Виконавець обстеження має бути учасником взаємодії.'
    ],

    // Field names for :attribute in validation messages
    'attributes' => [
        'primarySource' => 'джерело інформації обстеження',
        'reportOriginCode' => 'посилання на джерело обстеження',
        'reportOriginText' => 'опис джерела обстеження',
        'categorySystem' => 'система кодування обстеження',
        'categoryCode' => 'категорія обстеження',
        'codeSystem' => 'система кодування обстеження',
        'codeCode' => 'код обстеження',
        'effectiveDate' => 'дата отримання показників обстеження',
        'effectiveTime' => 'час отримання показників обстеження',
        'issuedDate' => 'дата внесення обстеження',
        'issuedTime' => 'час внесення обстеження',
        'interpretationCode' => 'інтерпретація спостереження',
        'bodySiteCode' => 'частина тіла обстеження',
        'methodCode' => 'метод спостереження',
        'reactionOn' => 'реакція на вакцинацію',
        'dictionaryName' => 'словник обстеження',
        'comment' => 'коментар обстеження',
        'components' => 'компоненти обстеження',
        'components.*.codeCode' => 'Величина та вид впливу обстеження',
        'components.*.codeSystem' => 'система кодування компоненту обстеження',
        'components.*.valueCode' => 'значення компоненту обстеження',
        'components.*.valueSystem' => 'система кодування значення компоненту осбтеження',
        'components.*.interpretationCode' => 'інтерпретація обстеження',
        'valueQuantityValue' => 'значення обстеження',
        'valueQuantityComparator' => 'порівняння значення обстеження',
        'valueQuantityUnit' => 'одиниця виміру значення',
        'valueQuantitySystem' => 'словник одиниці виміру значення',
        'valueQuantityCode' => 'код одиниці виміру значення',
        'valueCodeableConcept' => 'значення обстеження',
        'valueString' => 'значення обстеження',
        'valueBoolean' => 'значення обстеження',
        'valueDate' => 'значення (дата) обстеження',
        'valueTime' => 'значення (час) обстеження',
        'valueSampledDataData' => 'дані вибірки обстеження',
        'valueSampledDataOrigin' => 'початкове значення вибірки обстеження',
        'valueSampledDataPeriod' => 'період вибірки обстеження',
        'valueSampledDataFactor' => 'коефіцієнт вибірки обстеження',
        'valueSampledDataLowerLimit' => 'нижня межа вибірки обстеження',
        'valueSampledDataUpperLimit' => 'верхня межа вибірки обстеження',
        'valueSampledDataDimensions' => 'кількість вимірів вибірки обстеження',
        'valueRange' => 'діапазон значення обстеження',
        'valueRange.low' => 'нижня межа діапазону обстеження',
        'valueRange.high' => 'верхня межа діапазону обстеження',
        'valueRatio' => 'співвідношення значення обстеження',
        'valueRatio.numerator' => 'чисельник співвідношення обстеження',
        'valueRatio.denominator' => 'знаменник співвідношення обстеження'
    ]
];
