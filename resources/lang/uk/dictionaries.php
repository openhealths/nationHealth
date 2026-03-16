<?php

declare(strict_types=1);

return [
    'label' => 'Довідники',

    // Common translations for all program types
    'program_label' => 'Програма',
    'search_title' => 'Пошук програм',
    'mr_blank_type' => 'Тип рецептурного бланка',

    'medication_programs' => [
        'title' => 'Програми - Медикаменти',
        'prescription_medication' => 'Рецептурний лікарський засіб',

        // Program details block
        'funding_source' => 'Джерело фінансування',
        'care_plan_required' => "Обов'язковість використання плану лікування для EP",
        'employee_types_to_create_request' => 'Типи користувачів, яким дозволено виписувати EP',
        'speciality_types_allowed' => 'Перелік спеціальностей лікарів СМД та ПМД, яким дозволено виписувати EP/Призначення ПЛ',
        'skip_treatment_period' => 'Можливість виписувати EP на такий самий МНН протягом курсу лікування',
        'request_max_period_day' => 'Максимальна тривалість курсу лікування на який може бути виписаний EP за програмою',
        'skip_request_employee_declaration_verify' => 'Можливість виписувати EP незалежно від наявності укладеної декларації з пацієнтом',
        'skip_request_legal_entity_declaration_verify' => 'Можливість виписувати EP незалежно від наявності укладеної декларації в закладі, де виписується EP',
        'multi_medication_dispense_allowed' => 'Можливість часткового погашення EP',
        'request_notification_disabled' => 'Сповіщення пацієнта при операціях з рецептом вимкнено',
        'patient_categories_allowed' => 'Категорії пацієнтів, яким дозволено створення призначення ПЛ',
    ],

    'service_programs' => [
        'title' => 'Програми - Послуги',

        // Program details block
        'medical_guarantees' => 'Програма медичних гарантій',
        'care_plan_required' => "Обов'язковість використання плану лікування для ЕН"
    ],

    'drug_list' => [
        'title' => 'Лікарські засоби',
        'search' => 'Пошук ліків',

        // Additional filters
        'name' => 'Назва ЛЗ',
        'inn_name' => 'Міжнародна непатентована назва ЛЗ',
        'innm_dosage_form' => 'форма виписку ЛЗ',
        'medication_code_atc' => 'Код анатоміко-терапевтично-хімічної класифікації',
        'dosage_form' => 'Форма випуску ЛЗ',

        // Details panel
        'dosage_form_is_dosed' => 'Ознака дозованості',
        'ingredient' => [
            'label' => 'Складові',
            'name' => 'Назва складової',
            'is_primary' => 'Ознака пріоритетності складової',
            'dosage' => [
                'numerator_value' => 'Дозування складової',
                'numerator_unit' => 'Одиниця виміру дозування складової',
                'denumerator_value' => 'На яку кількість сутності визначене дозування складової',
                'denumerator_unit' => 'Одиниця виміру кількості сутності складової',
            ]
        ],
        'daily_dosage' => 'Підтримуюча добова доза ЛЗ до виписування',
        'max_daily_dosage' => 'Максимальна добова доза ЛЗ до виписування',
        'package' => [
            'label' => 'Пакування',
            'container_quantity' => "Кількість/об'єм ЛЗ в первинній упаковці",
            'container_quantity_unit' => "Одиниця виміру кількості/об'єму ЛЗ в первинній упаковці",
            'primary_packages_count' => 'Кількість первинних упаковок',
            'primary_package_unit' => 'Одиниця виміру первинної упаковки',
            'min_sale_quantity' => 'Мінімальна кількість ЛЗ до продажу',
            'package_quantity' => 'Кількість ЛЗ в упаковці',
            'max_request_quantity' => 'Максимальна кількість ЛЗ до виписування',
        ]
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

    'condition_diagnose' => [
        'title' => 'Каталог груп станів/діагнозів',
        'search_title' => 'Пошук груп станів/діагнозів',
        'group_label' => 'Група діагнозів'
    ],

    'forbidden_group' => [
        'title' => 'Каталог чутливих груп',
        'search_title' => 'Пошук чутливих груп',
        'group_label' => 'Обмежувальна група',
        'services_list_title' => 'Список послуг'
    ],

    'medical_device' => [
        'title' => 'Програми - медичні вироби',
        'medical_guarantees' => 'Програма медичних гарантій',
        'funding_source' => 'Джерело фінансування',
        'employee_types_to_create_request' => 'Категорії лікарів яким дозволено створювати е-запит',
        'speciality_types_allowed' => 'Перелік спеціальностей лікарів СМД, яким дозволено створювати е-запит',
        'skip_treatment_period' => 'Можливість створювати е-запит на такий самий медичний виріб протягом курс лікування',
        'request_max_period_day' => 'Максимальна тривалість курс лікування на який може бути виписаний е-запит за медичною програмою',
        'skip_request_employee_declaration_verify' => 'Можливість створювати е-запит незалежно від наявності укладеної декларації з пацієнтом',
        'skip_request_legal_entity_declaration_verify' => 'Можливість створювати е-запит незалежно від наявності укладеної декларації в закладі, де виписується EP',
    ],
];
