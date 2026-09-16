<?php

declare(strict_types=1);

return [
    'label' => 'Видачі медичних виробів',
    'search' => 'Пошук видач медичних виробів',
    'id' => 'ID видачі',
    'new' => 'Нова видача медичного виробу',
    'dispense' => 'Видати медичний виріб',
    'medical_device' => 'Медичний виріб',
    'product' => 'Виріб',
    'prescription_erequest' => 'Призначення на медичний виріб (е-запит)',
    'employee' => 'Працівник, який здійснив видачу',
    'division' => 'МНП видачі',
    'date_and_time' => 'Дата та час видачі',
    'quantity_integer' => 'Кількість (ціле число)',
    'specify_type_or_model' => 'Вказати тип або конкретну модель медичного виробу',
    'type' => 'Тип виробу',
    'model' => 'Конкретна модель',
    'device_type' => 'Тип медичного виробу',
    'select_device_type_or_model_first' => 'Спочатку оберіть тип або конкретну модель медичного виробу',
    'device_model' => 'Модель медичного виробу',
    'additional_information' => 'Додаткова інформація',
    'supporting_info' => 'Додаткова медична інформація',
    'note' => 'Нотатка',
    'select_episode_filter' => 'Обрати фільтр за епізодом',
    'filter_date_range' => 'Дата видачі від - до',
    'related_prescription_episode' => "Епізод пов'язаного призначення",
    'related_prescription_episode_id' => "ID епізоду пов'язаного призначення",
    'care_plan_id' => 'ID плану лікування',
    'procedure_id' => 'ID процедури',
    'legal_entity' => 'СГУСОЗ',
    'created_at' => 'Дата створення запису',
    'no_active_device_requests' => 'Активних призначень на медичні вироби не знайдено в ЕСОЗ.',
    'no_device_requests_without_program' => 'Усі знайдені призначення мають медичну програму. У взаємодії ЗОЗ їх не можна вказати в based_on (помилка ЕСОЗ: Device request with program can not be referenced). Видайте без призначення або оформіть відпуск в аптеці.',
    'based_on_optional_hint' => 'Поле необовʼязкове: можна видати виріб без е-запиту. Призначення з медпрограмою сюди не підставляються.',
    'procedure_optional_hint' => 'Необовʼязково. Зʼявляться процедури, додані в цю ж взаємодію.',
    'status' => [
        'in_progress' => 'В роботі',
        'completed' => 'Завершено',
        'stopped' => 'Зупинено',
        'entered_in_error' => 'Введено з помилкою',
        'unknown' => 'Невідомо',
        'preparation' => 'Підготовка',
        'canceled' => 'Скасовано'
    ],
    'validation' => [
        'device_request_not_available' => 'Обране призначення недоступне для видачі у взаємодії (має бути active, intent=order і без медичної програми).',
        'procedure_not_found' => 'Обрана процедура відсутня у взаємодії.',
        'employee_not_found' => 'Обраного працівника не знайдено.',
        'division_not_found' => 'Обране МНП не знайдено.',
        'device_definition_not_found' => 'Обрану модель медичного виробу не знайдено.',
        'device_not_matching_request' => 'Обраний медичний виріб не відповідає призначенню.',
        'quantity_exceeds_remaining' => 'Кількість виданих медичних виробів перевищує доступний залишок за призначенням.'
    ]
];
