<?php

declare(strict_types=1);

return [
    'new' => 'Нове обладнання',
    'name_medical_product' => 'Назва медичного обладнання',
    'name_type' => 'Тип назви',
    'type_medical_device' => 'Тип медичного обладнання',
    'serial_number' => 'Серійний №',
    'id' => 'eHealth ID',
    'recorder' => 'Працівник, що вносить дані',
    'recorded_by' => 'Працівник, що вніс дані',
    'additional_data' => 'Додаткові дані',
    'inventory_number' => 'Інвентарний №',
    'manufacturer' => 'Виробник',
    'manufacture_date' => 'Дата виробництва',
    'expiration_date' => 'Термін експлуатації надавачем',
    'model_number' => '№ моделі',
    'lot_number' => '№ закупівлі',
    'label' => 'Обладнання',
    'add' => 'Додати обладнання',
    'search' => 'Пошук обладнання',
    'name_or_inventory_number' => 'Назва або інвентарний №',
    'inserted_at' => 'Дата внесення даних',
    'parent_id' => "Пов'язане обладнання",
    'add_name' => 'Додати назву',
    'update_status' => 'Змінити статус',
    'update_availability_status' => 'Змінити доступність',
    'reason_for_status_change' => 'Причина зміни статусу',
    'update_equipment_status' => 'Оновити статус обладнання ',
    'update_equipment_availability' => 'Оновити доступність обладнання',
    'child_equipment_warning' => 'Це обладнання є батьківським. З ним зареєстровано таке обладнання:',

    'status' => [
        'active' => 'Активний',
        'inactive' => 'Неактивний',
        'entered_in_error' => 'Внесено помилково',
        'draft' => 'Чернетка'
    ],

    'availability_status' => [
        'label' => 'Доступність',
        'available' => 'Доступний',
        'damaged' => 'Пошкоджений',
        'destroyed' => 'Не підлягає відновленню',
        'lost' => 'Відсутній'
    ],

    'type' => [
        'patient_reported' => 'Вказана пацієнтом',
        'registered' => 'Зареєстрована',
        'user_friendly' => 'Користувацька'
    ],

    'success' => [
        'created' => 'Обладнання успішно створено',
        'draft_created' => 'Чернетку обладнання успішно створено',
        'status_updated' => 'Статус обладнання успішно оновлено',
        'availability_status_updated' => 'Доступність обладнання успішно оновлено',
    ],

    'policy' => [
        'create' => 'У вас немає дозволу на створення обладнання',
        'edit' => 'У вас немає дозволу на редагування обладнання',
        'update' => 'У вас немає дозволу на оновлення статусу обладнання',
        'update_availability_status' => 'У вас немає дозволу на оновлення доступності обладнання',
        'sync' => 'У вас немає дозволу на синхронізацію обладнань'
    ],

    'errors' => [
        'sync_failed' => 'Виникла помилка. Оновіть список місць надання послуг та працівників і спробуйте ще раз'
    ],

    'validation' => [
        'availability_status_unchanged' => 'Новий статус доступності повинен відрізнятися від поточного.',
        'not_belongs_to_division' => 'Обладнання не належить вибраному місцю надання послуг.',
        'no_equipment_in_division' => 'У вибраному МНП немає доступного обладнання.',
    ]
];
