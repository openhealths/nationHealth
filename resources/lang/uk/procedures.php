<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Procedures Language Lines
    |--------------------------------------------------------------------------
    |
    | The following language lines are for messages related to procedures,
    | e.g., creation form labels, cancellation modal, policy denials, etc.
    |
    */

    'label' => 'Процедура',
    'plural' => 'Процедури',
    'create' => 'Створити процедуру',
    'search' => 'Пошук процедури',
    'link' => 'Посилання на процедуру',
    'outcome_result' => 'Результат проведення',
    'performer' => 'виконавець процедури',
    'doctor_who_performed' => 'Лікар, що виконав',
    'performed_date_time' => 'Вказати дату та час проведення',
    'performed_period' => 'Вказати період проведення',
    'start_date_and_time' => 'Дата та час початку процедури',
    'end_date_and_time' => 'Дата та час завершення процедури',
    'reason_for_performing' => 'Причина проведення',
    'rehabilitation_aids' => 'Допоміжні засоби реабілітації',
    'complications' => 'Ускладнення, що виникли під час процедури',
    'used_reference_id' => 'ID використаного обладнання',
    'cancel_modal_description' => 'Дія є незворотною. Ви впевнені, що бажаєте позначити процедуру як внесену помилково? Медична документація, яка визначена такою, що внесена помилково, зберігається в електронній системі охорони здоров’я!',

    'status' => [
        'completed' => 'Завершений',
        'not_done' => 'Не виконана',
        'entered_in_error' => 'Внесений помилково'
    ],

    'policy' => [
        'create' => 'У вас немає дозволу на створення процедури.',
        'cancel' => 'У вас немає дозволу на позначення процедури внесеною помилково.'
    ],

    'messages' => [
        'synced_successfully' => 'Процедури успішно синхронізовані.',
        'first_page_synced_successfully' => 'Перша сторінка процедур синхронізована, решта обробляється у фоні.',
        'sync_already_running' => 'Синхронізація процедур вже запущена. Будь ласка, зачекайте її завершення.',
        'sync_resume_started' => 'Відновлення попередньої синхронізації процедур розпочато.',
        'sync_background_dispatch_error' => 'Помилка запуску фонової синхронізації процедур.',
        'writer_employee_not_found' => 'Не знайдено активного працівника для створення процедури.',
        'saved' => 'Процедуру успішно збережено.',
        'create_request_sent' => 'Заявку на створення процедури успішно відправлено.',
        'not_found' => 'Процедуру не знайдено.',
        'not_found_in_db' => 'Процедуру не знайдено в локальній базі даних. Спочатку синхронізуйте дані з ЕСОЗ.',
        'already_entered_in_error' => 'Процедуру вже позначено внесеною помилково.',
        'with_encounter_cannot_be_cancelled_separately' => 'Процедуру, створену у складі взаємодії, не можна позначити внесеною помилково окремо.',
        'cancel_request_sent' => 'Запит на позначення процедури внесеною помилково успішно відправлено.',
        'cancel_package_prepare_error' => 'Помилка підготовки процедури для позначення внесеною помилково.',
        'cancel_package_sign_error' => 'Помилка підписання процедури для позначення внесеною помилково.',
        'cancel_package_request_error' => 'Помилка відправлення запиту на позначення процедури внесеною помилково.',
        'cancel_package_save_error' => 'Помилка збереження оновленого статусу процедури після позначення внесеною помилково.'
    ],

    // Custom messages for validation rules
    'validation' => [
        'performer_required' => 'Для процедури потрібно вказати щонайменше одного виконавця.',
        'performer_employee_not_found' => 'Працівника, вказаного як виконавця процедури, не знайдено.',
        'performer_wrong_legal_entity' => 'Працівник :employee не належить вашому закладу.',
        'performer_invalid_status' => 'Невалідний статус працівника.',
        'performer_employee_invalid_type' => 'Тип працівника не дозволений як виконавець процедури.',
        'performer_not_participant' => 'Виконавець процедури має бути учасником взаємодії.'
    ],

    // Field names for :attribute in validation messages
    'attributes' => [
        'status' => 'статус процедури',
        'categoryCode' => 'категорія',
        'codeValue' => 'послуги',
        'primarySource' => 'джерело інформації',
        'performerEmployeeId' => 'виконавець процедури',
        'reportOriginCode' => 'джерело',
        'reportOriginText' => 'текст джерела',
        'divisionId' => 'місце надання послуг',
        'outcomeCode' => 'результат проведення',
        'performedType' => 'спосіб зазначення часу проведення',
        'performedDate' => 'дата проведення процедури',
        'performedTime' => 'час проведення процедури',
        'performedPeriodStartDate' => 'дата початку процедури',
        'performedPeriodStartTime' => 'час початку процедури',
        'performedPeriodEndDate' => 'дата завершення процедури',
        'performedPeriodEndTime' => 'час завершення процедури',
        'note' => 'коментар',
        'isReferralAvailable' => 'наявність направлення',
        'referralType' => 'тип направлення',
        'basedOnIdentifier' => 'номер електронного направлення',
        'paperReferralRequisition' => 'номер',
        'paperReferralRequesterEmployeeName' => 'автор',
        'paperReferralRequesterLegalEntityEdrpou' => 'ЄДРПОУ закладу, що виписав',
        'paperReferralRequesterLegalEntityName' => 'найменування закладу, що виписав',
        'paperReferralServiceRequestDate' => 'дата',
        'paperReferralNote' => 'нотатки',
        'usedCodes.*.code' => 'допоміжні засоби реабілітації',
        'usedReferences.*.id' => 'використане обладнання',
        'reasonReferences.*.id' => 'причина проведення',
        'reasonReferences.*.type' => 'тип причини проведення',
        'reasonReferences.*.codeCode' => 'код причини проведення',
        'complicationDetails.*.id' => 'ускладнення',
        'complicationDetails.*.type' => 'тип ускладнення',
        'complicationDetails.*.codeCode' => 'код ускладнення'
    ]
];
