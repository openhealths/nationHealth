<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Medical Events Vocabulary
|--------------------------------------------------------------------------
|
| Wording shared by every medical event of the encounter package. A record
| entered here has to read the same for a condition, a procedure or a device,
| so anything meaningful for a single entity belongs to that entity's file.
|
*/

return [
    'information_source' => 'Джерело інформації',
    'other_source' => 'Інше джерело',
    'performer' => 'Виконавець',
    'source_link' => 'Посилання на джерело',
    'code_and_name' => 'Код та назва',
    'added' => 'Додано',
    'medical_records_type' => 'тип медичних записів',
    'mark_as_error' => 'Позначити помилковим',
    'medical_record_id' => 'ID Мед. Запису',
    'icpc2_status_code' => 'Код стану за ICPC-2',
    'duplicate_code_warning' => 'Такий код вже існує',
    'equipment_search' => 'Пошук обладнання',
    'equipment_add' => 'Додати обладнання',

    // Referral a record is based on, filled the same way for every record that accepts one
    'referral' => [
        'available' => 'Є направлення',
        'requisition_type' => 'Тип направлення',
        'electronic' => 'Електронне направлення',
        'electronic_id' => 'ID електронного направлення',
        'paper' => 'Паперове направлення',
        'author' => 'Автор',
        'edrpou_of_the_issuing_institution' => 'ЄДРПОУ закладу, що виписав',
        'name_of_the_institution_that_issued_it' => 'Найменування закладу, що виписав',
        'exhausted' => 'Це електронне направлення вже вичерпано.',
        'notes' => 'Нотатки',
        'number' => 'Номер',
        'date' => 'Дата'
    ],

    // Wording of the "mark as entered in error" modal, identical for every record it opens for.
    // The description belongs to the entity, because it names what is being cancelled.
    'cancel_modal' => [
        'title' => 'Підтвердження щодо визначення помилково внесеної медичної документації про пацієнта в ЕСОЗ',
        'reason_label' => 'Підстава помилкового внесення медичної документації',
        'reason_placeholder' => 'Підстава',
        'explanation_label' => 'Обґрунтування підстав визначення помилкового внесення медичної документації',
        'confirm_button' => 'Позначити документ помилково внесеним'
    ],

    // Outcomes and notices shown to the user
    'messages' => [
        'cancel_device_group_note' => "Медичні вироби, їхні зв'язки з пацієнтом та виявлені проблеми визнаються внесеними помилково лише разом: позначте будь-який запис — і всі решта цих трьох розділів будуть позначені теж.",
        'cancel_device_group_warning' => "Разом із цим медичним виробом внесеними помилково будуть визнані решта медичних виробів, усі зв'язки з пацієнтом та всі виявлені проблеми, створені в тій самій взаємодії. Інші записи взаємодії та вона сама залишаться без змін."
    ],

    // Custom messages for validation rules
    'validation' => [
        'primary_source_required_for_assistant' => 'Молодший медичний персонал може вносити лише дані з первинного джерела',
        'no_equipment_in_division' => 'У вибраному МНП немає доступного обладнання.'
    ]
];
