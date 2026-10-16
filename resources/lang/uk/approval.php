<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Approval Language Lines
    |--------------------------------------------------------------------------
    |
    | The following language lines are used for data access approvals.
    |
    */

    'data_access_list' => 'Список доступів до даних',
    'select_patient_to_sync' => 'Для синхронізації оберіть пацієнта',
    'employee' => 'Співробітник',
    'access_details' => 'Деталі доступу',
    'search_access' => 'Пошук доступу',
    'data_id' => 'ID даних',
    'reason_id' => 'ID причини',
    'granted_to' => 'Кому надано доступ',
    'data_type' => 'Тип даних',
    'access_level' => 'Рівень доступу',
    'data' => 'Дані',
    'based_on' => 'На підставі',
    'revoke_access' => 'Відмінити доступ',
    'access_author' => 'Автор доступу',
    'access_expires' => 'Термін дії доступу (діє до)',
    'reason' => 'Причина (reason)',
    'is_verified' => 'Верифікований',
    'is_expired' => 'Термін дії минув',
    'yes' => 'Так',
    'no' => 'Ні',
    'resource_types' => [
        'patient' => 'Всі медичні дані пацієнта',
        'care_plan' => 'План лікування',
        'diagnostic_report' => 'Діагностичний звіт',
        'episode_of_care' => 'Епізод медичної допомоги',
        'encounter' => 'Взаємодія',
        'service_request' => 'Направлення',
        'procedure' => 'Процедура',
        'observation' => 'Спостереження',
        'diagnoses_group' => 'Група діагнозів',
        'services_group' => 'Група послуг',
        'forbidden_group' => 'Група чутливих даних',
        'device' => 'Медичний виріб',
        'device_association' => 'Пов’язаний медичний виріб',
        'detected_issue' => 'Виявлена проблема',
        'composition' => 'Медичний документ',
        'person' => 'Персональні дані',
    ],
];
