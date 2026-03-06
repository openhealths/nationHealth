<?php

declare(strict_types=1);

return [
    'label' => 'Довідники',

    // Common translations for all program types
    'program_label' => 'Програма',
    'search_title' => 'Пошук програм',

    'medication_programs' => [
        'title' => 'Програми - Медикаменти',
        'description' => 'Управління медичними програмами',
        'index' => 'Список медичних програм',
        'create' => 'Створити медичну програму',
        'prescription_medication' => 'Рецептурний лікарський засіб',
        'program_option_prescription_medication' => 'Рецептурний лікарський засіб',

        // Program details block
        'funding_source' => 'Джерело фінансування:',
        'prescription_form_type' => 'Тип рецептурного бланка:',
        'treatment_plan_required' => 'Обов\'язковість використання плану лікування для EP:',
        'allowed_user_types' => 'Тип користувачів, яким дозволено виписувати EP:',
        'allowed_specialties' => 'Перелік спеціальностей лікарів СМД та ПМД, яким дозволено виписувати EP/Призначення ПЛ:',
        'same_inn_course' => 'Можливість виписувати EP на такий самий МНН протягом курсу лікування:',
        'max_course_duration' => 'Максимальна тривалість курсу лікування на який може бути виписаний EP за програмою:',
        'no_declaration_required_patient' => 'Можливість виписувати EP незалежно від наявності укладеної декларації з пацієнтом:',
        'no_declaration_required_facility' => 'Можливість виписувати EP незалежно від наявності укладеної декларації в закладі, де виписується EP:',
        'partial_redemption' => 'Можливість часткового погашення EP:',
        'patient_notifications_off' => 'Сповіщення пацієнта при операціях з рецептом вимкнено:',
        'allowed_patient_categories' => 'Категорії пацієнтів, яким дозволено створення призначення ПЛ:',
    ],

    'service_programs' => [
        'title' => 'Програми - Послуги',
        'description' => 'Управління сервісними програмами',
        'index' => 'Список сервісних програм',
        'create' => 'Створити сервісну програму',

        // Program details block
        'medical_guarantees' => 'Програма медичних гарантій',
        'treatment_plan_required_en' => 'Обов\'язковість використання плану лікування для ЕН:',
    ],

    'loinc_observation_dictionary' => 'Довідник спостережень LOINC',
    'icf_dictionary_condition_patient' => 'Довідник станів пацієнта МКФ',
];
