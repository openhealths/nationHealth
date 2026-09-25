<?php

declare(strict_types=1);

return [
    'label' => 'Асоційовані медичні вироби',
    'new' => 'Новий медичний виріб',
    'add' => 'Додати медичний виріб',
    'name' => 'Назва медичного виробу',
    'name_type' => 'Тип назви',
    'add_name' => 'Додати назву',
    'type' => 'Тип медичного виробу',
    'model_number' => 'Модель',
    'definition' => 'Визначення',
    'manufacturer' => 'Виробник',
    'serial_number' => 'Серійний номер',
    'lot_number' => 'Номер партії',
    'manufacture_date' => 'Дата виробництва',
    'expiration_date' => 'Термін придатності',
    'external_system' => 'Зовнішня система',
    'external_system_identifier' => 'Ідентифікатор зовнішньої системи',
    'add_external_system_identifier' => 'Додати ідентифікатор зовнішньої системи',
    'parent' => 'Батьківський виріб',
    'property' => 'Додаткова властивість',
    'add_property' => 'Додати додаткову властивість',
    'property_value_type' => 'Тип значення',
    'value_types' => [
        'codeable_concept' => 'Кодоване значення',
        'quantity' => 'Величина',
        'range' => 'Діапазон',
        'boolean' => 'Так або ні',
        'integer' => 'Ціле число',
        'string' => 'Текст'
    ],
    'value' => 'Значення',
    'value_system' => 'Система кодування',
    'value_code' => 'Код значення',
    'value_comparator' => 'Порівняння',
    'value_unit' => 'Одиниця вимірювання',
    'value_range_low' => 'Від',
    'value_range_high' => 'До',
    'legal_entity' => 'СГУСОЗ',
    'definition_full' => 'Модель виробу з довідника/Посилання на ідентифікатор медичного виробу',
    'recorder' => 'Автор запису',
    'doctor' => 'Лікар',
    'report_origin' => 'Джерело інформації',
    'primary_source' => 'Первинне джерело інформації',
    'source_data' => 'Дані про джерело *',
    'other_source' => 'Інше джерело',
    'source_reference' => 'Посилання на джерело *',
    'created_at_system' => 'Дата та час внесення запису в Систему',
    'updated_at_system' => 'Дата та час оновлення запису в Системі',
    'notes' => 'Примітки',
    'encounter_id_label' => 'Ідентифікатор взаємодії, в рамках якої створений виріб',
    'device_id_label' => 'Ідентифікатор запису (ID виробу)',
    'entered_in_error_reason' => 'Причина позначення запису помилково внесеним',
    'explanatory_letter' => 'Обґрунтування підстави позначення запису помилково внесеним',
    'plural' => 'Пристрої',
    'search' => 'Пошук медичного виробу',
    'device_id' => 'ID виробу',
    'properties' => 'Властивості',
    'manufacturer_and_serial' => 'Виробник, серійний',
    'record_creation_date' => 'Дата створення запису',
    'inserted_at' => 'Створено',
    'updated_at' => 'Оновлено',
    'context_id' => 'ID взаємодії',

    'status' => [
        'active' => 'Активний',
        'inactive' => 'Неактивний',
        'entered_in_error' => 'Внесений помилково'
    ],

    'policy' => [
        'create' => 'У вас немає дозволу на створення асоційованого медичного виробу.'
    ],

    'messages' => [
        'synced_successfully' => 'Медичні вироби успішно синхронізовані',
        'record_synced_successfully' => 'Медичний виріб успішно синхронізований',
        'first_page_synced_successfully' => 'Перша сторінка медичних виробів синхронізована, решта обробляється у фоні',
        'sync_already_running' => 'Синхронізація медичних виробів вже запущена. Будь ласка, зачекайте її завершення.',
        'sync_resume_started' => 'Відновлення попередньої синхронізації медичних виробів розпочато',
        'sync_background_dispatch_error' => 'Помилка запуску фонової синхронізації медичних виробів'
    ],

    // Custom messages for validation rules
    'validation' => [
        'duplicated_name_type' => 'Для одного виробу не можна вказати дві назви одного типу',
        'association_required' => "Для медичного виробу треба додати зв'язок з пацієнтом у статусі «Імплантований» або «Прикріплений»",
        'type_not_allowed' => 'Обраний тип медичного виробу недоступний',
        'property_value_required' => 'Для характеристики медичного виробу №:position треба вказати значення',
        'property_single_value' => 'Для характеристики медичного виробу №:position можна вказати лише одне значення',
        'definition_type_mismatch' => 'Тип медичного виробу не збігається з типом обраного виробу з довідника'
    ],

    // Number of the record every :attribute of a validation message carries
    'position' => 'медичний виріб №:position',

    // Field names for :attribute in validation messages
    'attributes' => [
        'status' => 'статус медичного виробу',
        'typeCode' => 'тип медичного виробу',
        'names' => 'назви медичного виробу',
        'names.*.type' => 'тип назви медичного виробу',
        'names.*.value' => 'назва медичного виробу',
        'modelNumber' => 'модель медичного виробу',
        'lotNumber' => 'номер партії медичного виробу',
        'manufacturer' => 'виробник медичного виробу',
        'serialNumber' => 'серійний номер медичного виробу',
        'manufactureDate' => 'дата виробництва медичного виробу',
        'expirationDate' => 'термін придатності медичного виробу',
        'note' => 'коментар до медичного виробу',
        'primarySource' => 'джерело інформації про медичний виріб',
        'reportOriginCode' => 'посилання на джерело інформації про медичний виріб',
        'reportOriginText' => 'опис джерела інформації про медичний виріб',
        'definitionId' => 'модель виробу з довідника',
        'parentId' => 'батьківський медичний виріб',
        'properties' => 'додаткові властивості медичного виробу',
        'properties.*.code' => 'додаткова властивість медичного виробу',
        'properties.*.valueCodeableConceptSystem' => 'система кодування значення властивості',
        'properties.*.valueCodeableConceptCode' => 'код значення властивості',
        'properties.*.valueQuantityValue' => 'значення властивості',
        'properties.*.valueQuantityComparator' => 'порівняння значення властивості',
        'properties.*.valueQuantityUnit' => 'одиниця вимірювання значення властивості',
        'properties.*.valueQuantitySystem' => 'система кодування одиниці вимірювання властивості',
        'properties.*.valueQuantityCode' => 'код одиниці вимірювання властивості',
        'properties.*.valueRangeLowValue' => 'нижня межа значення властивості',
        'properties.*.valueRangeLowUnit' => 'одиниця вимірювання нижньої межі властивості',
        'properties.*.valueRangeLowSystem' => 'система кодування нижньої межі властивості',
        'properties.*.valueRangeLowCode' => 'код одиниці вимірювання нижньої межі властивості',
        'properties.*.valueRangeHighValue' => 'верхня межа значення властивості',
        'properties.*.valueRangeHighUnit' => 'одиниця вимірювання верхньої межі властивості',
        'properties.*.valueRangeHighSystem' => 'система кодування верхньої межі властивості',
        'properties.*.valueRangeHighCode' => 'код одиниці вимірювання верхньої межі властивості',
        'properties.*.valueBoolean' => 'значення властивості',
        'properties.*.valueInteger' => 'значення властивості',
        'properties.*.valueString' => 'значення властивості',
        'identifiers' => 'зовнішні системи медичного виробу',
        'identifiers.*.code' => 'зовнішня система',
        'identifiers.*.text' => 'опис зовнішньої системи',
        'identifiers.*.value' => 'ідентифікатор зовнішньої системи'
    ]
];
