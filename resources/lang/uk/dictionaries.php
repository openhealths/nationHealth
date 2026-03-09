<?php

declare(strict_types=1);

return [
    'label' => 'Довідники',

    // Common translations for all program types
    'program_label' => 'Програма',
    'search_title' => 'Пошук програм',

    'medication_programs' => [
        'title' => 'Програми - Медикаменти',
        'prescription_medication' => 'Рецептурний лікарський засіб',

        // Program details block
        'funding_source' => 'Джерело фінансування',
        'mr_blank_type' => 'Тип рецептурного бланка',
        'care_plan_required' => "Обов'язковість використання плану лікування для EP",
        'employee_types_to_create_request' => 'Типи користувачів, яким дозволено виписувати EP',
        'speciality_types_allowed' => 'Перелік спеціальностей лікарів СМД та ПМД, яким дозволено виписувати EP/Призначення ПЛ',
        'skip_treatment_period' => 'Можливість виписувати EP на такий самий МНН протягом курсу лікування',
        'request_max_period_day' => 'Максимальна тривалість курсу лікування на який може бути виписаний EP за програмою',
        'skip_request_employee_declaration_verify' => 'Можливість виписувати EP незалежно від наявності укладеної декларації з пацієнтом',
        'skip_request_legal_entity_declaration_verify' => 'Можливість виписувати EP незалежно від наявності укладеної декларації в закладі, де виписується EP',
        'multi_medication_dispense_allowed' => 'Можливість часткового погашення EP',
        'request_notification_disabled' => 'Сповіщення пацієнта при операціях з рецептом вимкнено',
        'patient_categories_allowed' => 'Категорії пацієнтів, яким дозволено створення призначення ПЛ'
    ],

    'service_programs' => [
        'title' => 'Програми - Послуги',

        // Program details block
        'medical_guarantees' => 'Програма медичних гарантій',
        'care_plan_required' => "Обов'язковість використання плану лікування для ЕН"
    ],

    'service_catalog' => [
        'title' => 'Каталог послуг',
        'search_services' => 'Пошук послуг',
        'search_placeholder' => 'Киснева терапія',
        'service_category' => 'Категорія послуг',
        'service_active' => 'Послуга активна',
        'service_group_active' => 'Група послуг активна',
        'allowed_for_en' => 'Дозволяється використання у ЕН',

        'categories' => [
            'nervous_system' => 'Процедури на нервовій системі',
        ],
    ],

    'loinc_observation_dictionary' => 'Довідник спостережень LOINC',
    'icf_dictionary_condition_patient' => 'Довідник станів пацієнта МКФ',
];
