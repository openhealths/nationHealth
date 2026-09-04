<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Immunizations Language Lines
    |--------------------------------------------------------------------------
    |
    | The following language lines are for messages related to immunizations,
    | e.g., vaccine search, vaccination protocol, record listings, etc.
    |
    */

    'label' => 'Вакцинація',
    'plural' => 'Вакцинації',
    'search' => 'Пошук вакцинації',
    'add' => 'Додати вакцинацію',
    'reaction_on' => 'Реакція на вакцинацію',
    'vaccine' => 'Вакцина',
    'vaccine_search' => 'Пошук вакцини',
    'vaccine_name' => 'Назва вакцини',
    'vaccine_code' => 'Код вакцини',
    'vaccine_code_and_name' => 'Код вакцини та назва',
    'disease' => 'Хвороба',
    'select_vaccine' => 'Обрати вакцину',
    'choose_another_vaccine' => 'Обрати іншу вакцину',
    'vaccine_information' => 'Інформація про вакцину',
    'manufacturer' => 'Виробник',
    'manufacturer_and_lot_number' => 'Виробник та партія',
    'amount_of_injected' => 'Кількість введеної',
    'measurement_units' => 'Одиниці виміру',
    'input_route' => 'Шлях введення',
    'route' => 'Шлях',
    'data' => 'Дані',
    'was_performed' => 'Чи була проведена',
    'date_time_performed' => 'Дата та час проведення',
    'reasons' => 'Причини',
    'reactions' => 'Реакції',
    'vaccination_protocol' => 'Протокол імунізації',
    'dose_sequence' => 'Порядковий номер дози',
    'series' => 'Етап імунізації',
    'target_diseases' => 'Протидія загрозам',
    'protocol_author' => 'Автор протоколу',
    'series_of_doses_by_protocol' => 'Кількість доз по протоколу',
    'protocol_description' => 'Опис протоколу',
    'vaccination_protocol_required' => 'Необхідно додати принаймні один протокол вакцинації.',
    'vaccination_protocol_required_fields' => 'Для цієї вакцинації необхідно заповнити порядковий номер дози, етап імунізації та кількість доз по протоколу.',
    'duplicate_target_disease_in_protocol' => 'Цільова хвороба не може повторюватися в межах однієї вакцинації.',
    'vaccine_target_disease_mismatch' => 'Обрана цільова хвороба не відповідає коду вакцини.',

    'status' => [
        'completed' => 'Завершений',
        'entered_in_error' => 'Внесений помилково'
    ],

    'messages' => [
        'synced_successfully' => 'Вакцинації успішно синхронізовані',
        'first_page_synced_successfully' => 'Перша сторінка вакцинацій синхронізована, решта обробляється у фоні',
        'sync_already_running' => 'Синхронізація вакцинацій вже запущена. Будь ласка, зачекайте її завершення.',
        'sync_resume_started' => 'Відновлення попередньої синхронізації вакцинацій розпочато',
        'sync_background_dispatch_error' => 'Помилка запуску фонової синхронізації вакцинацій'
    ],

    // Custom messages for validation rules
    'validation' => [
        'not_given_by_patient' => 'Зі слів пацієнта можна вносити лише проведену вакцинацію'
    ],

    // Field names for :attribute in validation messages
    'attributes' => [
        'primarySource' => 'джерело інформації вакцинації',
        'notGiven' => 'чи була проведена вакцинація',
        'vaccineCode' => 'код та назва вакцини',
        'date' => 'дата вакцинації',
        'time' => 'час вакцинації',
        'reasons' => 'причини проведення вакцинації',
        'reasons.*.code' => 'причина проведення вакцинації',
        'reasonNotGivenCode' => 'причина не проведення вакцинації',
        'reportOriginCode' => 'посилання на джерело інформації вакцинації',
        'reportOriginText' => 'опис джерела інформації вакцинації',
        'manufacturer' => 'виробник вакцини',
        'lotNumber' => 'серія вакцини',
        'expirationDate' => 'дата закінчення придатності вакцини',
        'siteCode' => 'частина тіла вакцини',
        'routeCode' => 'шлях введення вакцини',
        'doseQuantityValue' => 'кількість введеної вакцини',
        'doseQuantityCode' => 'одиниця виміру вакцини',
        'doseQuantityUnit' => 'назва одиниці виміру вакцини',
        'vaccinationProtocols' => 'протоколи імунізації',
        'vaccinationProtocols.*.authorityCode' => 'автор протоколу імунізації',
        'vaccinationProtocols.*.doseSequence' => 'порядковий номер дози імунізації',
        'vaccinationProtocols.*.series' => 'етап імунізації',
        'vaccinationProtocols.*.seriesDoses' => 'кількість доз по протоколу імунізації',
        'vaccinationProtocols.*.description' => 'опис протоколу імунізації',
        'vaccinationProtocols.*.targetDiseaseCodes' => 'протидія загрозам імунізації',
        'vaccinationProtocols.*.targetDiseaseCodes.*' => 'код протидії загрозам імунізації'
    ]
];
