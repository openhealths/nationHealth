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
    'conclusion' => 'Заключення',
    'medical_records_type' => 'тип медичних записів',

    // Wording of the "mark as entered in error" modal, identical for every record it opens for.
    // The description belongs to the entity, because it names what is being cancelled.
    'cancel_modal' => [
        'title' => 'Підтвердження щодо визначення помилково внесеної медичної документації про пацієнта в ЕСОЗ',
        'reason_label' => 'Підстава помилкового внесення медичної документації',
        'reason_placeholder' => 'Підстава',
        'explanation_label' => 'Обґрунтування підстав визначення помилкового внесення медичної документації',
        'confirm_button' => 'Позначити документ помилково внесеним'
    ]
];
