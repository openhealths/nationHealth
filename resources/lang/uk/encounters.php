<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Encounters Language Lines
    |--------------------------------------------------------------------------
    |
    | The following language lines are for messages related to encounters,
    | e.g., encounter form sections, referral block, cancellation modal,
    | policy denials, etc.
    |
    */

    'label' => 'Взаємодія',
    'plural' => 'Взаємодії',
    'search' => 'Пошук взаємодій',
    'new' => 'Нова взаємодія',
    'start' => 'Розпочати взаємодію',
    'records_entered_in_error' => 'Позначити записи помилковими',
    'add_observations_reports_conditions' => 'Додати спостереження, звіт або стан',
    'interaction_class' => 'Клас взаємодії',
    'interaction_type' => 'Тип взаємодії',
    'period' => 'Дата',
    'period_date' => 'Дата',
    'period_start' => 'Час початку',
    'priority' => 'Пріоритет',
    'performer_speciality' => 'Спеціалізація лікаря',
    'diagnosis_performer' => 'виконавець діагнозу',
    'episode_id' => 'ID епізоду',
    'origin_episode' => 'Первинний епізод',
    'episode_existing' => 'Існуючий епізод',
    'episode_new' => 'Новий епізод',
    'episode_name' => 'Назва епізоду',
    'episode_type' => 'Тип епізоду',
    'incoming_referral' => 'Направлення',
    'referral_available' => 'Є направлення',
    'referral_type' => 'Тип направлення',
    'electronic_referral' => 'Електронне направлення',
    'paper_referral' => 'Паперове направлення',
    'referral_number' => 'Номер направлення',
    'paper_referral_author' => 'Автор направлення',
    'paper_referral_edrpou_short' => 'ЄДРПОУ закладу',
    'paper_referral_institution_short' => 'Найменування закладу',
    'paper_referral_date' => 'Дата направлення',
    'paper_referral_notes' => 'Нотатки',
    'hospitalization' => [
        'label' => 'Госпіталізація',
        'admit_source' => 'Джерело госпіталізації',
        're_admission' => 'Повторна госпіталізація',
        'pre_admission_identifier' => 'Номер заявки ЕМД / ідентифікатор догоспіталізації',
        'destination' => 'Заклад, куди направляється пацієнт після виписки',
        'discharge_disposition' => 'Куди вибув пацієнт після виписки',
        'discharge_department' => 'Відділення, з якого виписано пацієнта'
    ],
    'reasons_for_visit' => 'Причини звернення',
    'reason_for_visit' => 'Причина звернення',
    'reason_text' => 'Коментар',
    'action_text' => 'Коментар',
    'additional_data' => 'Додаткові дані',
    'services' => 'Послуги',
    'service' => 'Послуга',
    'select_service' => 'Обрати послугу',
    'add_service' => 'Додати послугу',
    'search_medical_records' => 'Пошук медичних записів',
    'assignments' => 'Призначення',
    'write_assignments_here' => 'Напишіть призначення тут',
    'period_end' => 'Час закінчення',
    'add_coauthor' => 'Додати співавтора',
    'find_doctor' => 'Знайти лікаря',
    'coauthor' => 'Співавтор',
    'additional_actions' => 'Додаткові дії',
    'add_prescription' => 'Додати рецепт',
    'add_referral' => 'Додати направлення',
    'add_medical_report' => 'Додати медичний висновок',
    'add_care_plan' => 'Додати план лікування',
    'cancel_modal_description' => 'Дія є незворотною. Разом із взаємодією помилково внесеними будуть позначені всі діагнози, спостереження та імунізації, створені в її межах. Медична документація, яка визначена такою, що внесена помилково, зберігається в електронній системі охорони здоров’я!',
    'records_cancel_modal_description' => 'Дія є незворотною. Помилково внесеними будуть позначені лише вибрані записи, сама взаємодія залишиться чинною. Медична документація, яка визначена такою, що внесена помилково, зберігається в електронній системі охорони здоров’я!',

    'referral_category' => [
        'procedure' => 'Процедура',
        'surgical_procedure' => 'Хірургічна процедура',
        'diagnostic_procedure' => 'Діагностична процедура',
        'imaging' => 'Візуалізація',
        'diagnostic' => 'Діагностичне дослідження',
        'education' => 'Навчання пацієнта',
        'counseling' => 'Консультування',
        'hospital_referral' => 'Направлення в стаціонар',
        'consultation' => 'Консультація',
        'evaluation' => 'Оцінювання',
        'hospitalization' => 'Госпіталізація',
        'laboratory_procedure' => 'Лабораторна процедура',
        'transfer' => 'Переведення',
        'treatment' => 'Лікування'
    ],

    'priority_options' => [
        'routine' => 'Планове',
        'urgent' => 'Ургентне',
        'asap' => 'Якнайшвидше',
        'stat' => 'Негайно'
    ],

    'status' => [
        'draft' => 'Чернетка',
        'finished' => 'Завершений',
        'entered_in_error' => 'Внесений помилково'
    ],

    'policy' => [
        'create' => 'У вас немає дозволу на створення взаємодії.',
        'cancel' => 'У вас немає дозволу на позначення взаємодії внесеною помилково.'
    ],

    'messages' => [
        'synced_successfully' => 'Взаємодії успішно синхронізовані',
        'first_page_synced_successfully' => 'Перша сторінка взаємодій синхронізована, інші сторінки обробляються у фоновому режимі',
        'sync_already_running' => 'Синхронізація взаємодій вже запущена. Будь ласка, зачекайте її завершення.',
        'sync_resume_started' => 'Відновлення попередньої синхронізації взаємодій розпочато',
        'sync_background_dispatch_error' => 'Помилка запуску фонової синхронізації взаємодій',
        'unexpected_error' => 'Виникла непередбачувана помилка.',
        'created' => 'Взаємодія успішно створена.',
        'updated' => 'Взаємодія успішно оновлена.',
        'not_found_in_db' => 'Ця взаємодія ще не завантажена з ЕСОЗ. Синхронізуйте дані та спробуйте ще раз.',
        'record_not_found_in_db' => 'Цей запис ще не завантажений з ЕСОЗ. Синхронізуйте дані та спробуйте ще раз.',
        'cancel_request_sent' => 'Запит на позначення взаємодії внесеною помилково успішно відправлено.',
        'records_cancel_request_sent' => 'Запит на позначення вибраних записів помилковими успішно відправлено.',
        'cancel_records_not_picked' => 'Позначте записи, які потрібно визнати внесеними помилково.',
        'cancel_package_prepare_error' => 'Помилка підготовки пакета взаємодії для позначення внесеною помилково.',
        'cancel_package_sign_error' => 'Помилка підписання пакета взаємодії для позначення внесеною помилково.',
        'cancel_package_request_error' => 'Помилка відправлення запиту на позначення взаємодії внесеною помилково.',
        'cancel_package_save_error' => 'Помилка збереження оновленого пакета взаємодії після позначення внесеною помилково.',
        'referral_not_found' => 'Направлення не знайдено.',
        'created_and_sent' => 'Взаємодію успішно створено та надіслано до ЕСОЗ.',
        'signed_and_sent' => 'Взаємодію успішно підписано та надіслано до ЕСОЗ.',
        'job_id_not_received' => 'Не вдалося отримати Job ID від ЕСОЗ.',
        'referral_redeemed' => 'Направлення успішно погашено!',
        'referral_redeem_error' => 'Не вдалося погасити направлення: :message',
    ]
];
