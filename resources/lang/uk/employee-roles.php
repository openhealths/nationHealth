<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Employee Roles Language Lines
    |--------------------------------------------------------------------------
    |
    | The following language lines are for various messages related to employee roles,
    | e.g., employee roles search, employee roles-related API request messages, etc.,
    |
    */

    'label' => 'Ролі',
    'role' => 'Роль',
    'new' => 'Нова роль',
    'search_by_employee' => 'Пошук за ПІБ працівника',
    'speciality_type' => 'Вид послуги',
    'end_date' => 'Дата деактивації',
    'status' => 'Статус ролі',
    'employeeId' => 'Працівник, що виконує роль',
    'healthcareServiceId' => 'Вид медичної послуги',
    'deactivate' => 'Деактивація ролі',
    'deactivate_warning' => "Увага! Деактивація ролі в електронній системі охорони здоров'я є незворотною дією.",
    'id' => 'ID ролі',
    'start_datetime' => 'Дата та час початку дії',
    'end_datetime' => 'Дата та час деактивації',
    'inserted_at' => 'Дата та час створення',
    'inserted_by' => 'Ким створено',
    'updated_at' => 'Дата та час оновлення',
    'updated_by' => 'Ким оновлено',

    'success' => [
        'created' => 'Роль успішно додано',
        'deactivated' => 'Роль успішно деактивовано'
    ],

    'policy' => [
        'create' => 'У вас немає дозволу на додавання ролі працівнику',
        'deactivate' => 'У вас немає дозволу на деактивування ролі',
        'sync' => 'У вас немає дозволу на синхронізацію ролей працівників'
    ],

    'errors' => [
        'sync_failed' => 'Виникла помилка. Оновіть список працівників і послуги та спробуйте ще раз'
    ]
];
