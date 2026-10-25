# Консолідація Symfony ObjectMapper у #898

Оновлено 25.10.2026. Основний draft: [openhealths/nationHealth#898](https://github.com/openhealths/nationHealth/pull/898), issue [#841](https://github.com/openhealths/nationHealth/issues/841). Head — `Mefizz:i841_object_mapper_service_request`, база — company main `b43c425ecc53738460e8e3c27fcef52952f6decf`.

## Що зведено

- Повний попередній клінічний рефактор #898, включно з локальними незавершеними правками сумісності. Перед перебазуванням збережені snapshots опублікованої та локальної версій. Історія агрегована перед rebase; старі коміти не є новими результатами перевірок.
- Composition/PaperReferral та Form hydration з #1045, implementation head `4401e707f6be53dac4f618aefda2281020e0125e`.
- Canonical CarePlan/Activity з fork #27, implementation head `257b6df08f8d4a4f52a70e00be521ec4c7564110`, разом із передумовами #1022/#998. CarePlan audit head — `e7fbd53aacaed0ccc9d7cfb0708a788aa18e63a9`. До їхнього merge ці передумови видно в company diff; повторний rebase має прибрати їх із власного diff після інтеграції.

Окремі PR не замінюють #898. Після підтвердження публікації консолідованого head їх можна закрити як поглинуті, зберігаючи посилання та історичні результати.

## Узгодження з main

Збережено перейменування Livewire `EncounterPackage`, нові standalone DeviceRequest form/registry та їхні policies/routes. Старий DeviceRequest stub не повертався. Нові transfer-of-care performer/location/type поля перевіряються через Symfony DTO. ClinicalImpression підтримує effectiveDateTime та відкритий effectivePeriod, DeviceRequest authored_on відповідає поточному main без колишнього зсуву на 30 секунд. Ці зміни контракту задокументовані окремо від legacy golden fixtures.

CarePlan/Activity читають повний канонічний ресурс через Repository. Старі flat DTO assertions замінені незалежними audit fixtures, реальними Form cases, API signing contracts і FHIR persistence tests. Невикористаний flat activity payload concern та його test adapter видалені. Нові DTO не виконують SQL/HTTP/Auth та не створюють UUID/час.

## Перевірки поточної консолідації

- Mapping: **445 / 2511, exit 0**, одне наявне PDO deprecation.
- Unit Encounter referral/inpatient approval: **9 / 52, exit 0**.
- Короткий API/ClinicalImpression/Blade прогін: **16 / 63, exit 0**, частково входить у Mapping.
- Перший повний domain прогін: **1262 / 5547, exit 1; 31 errors, 11 failures, 25 risky**. Після виправлень усі проблемні сценарії та opaque eRx draft повторно пройдені: **43 / 226, exit 0**, без errors/failures/skipped/risky. Повтор всього domain набору на фінальному head очікується в CI; перший невдалий прогін не називається успішним.
- PHP lint для 29 змінених файлів, shell syntax, diff check пройдено.

Під час консолідації додатково відновлено Repository-перевірку відкритих документів для status gate призначення та узгоджено параметри посилань із перейменованим EncounterPackage route. Тести DeviceDefinition використовують partial API doubles, щоб виконувати перенесені чисті predicates. Старий flat Referral setup переведено на вже наявні FHIR fixture helpers. Canonical Activity projection перед referral draft читається scoped Repository методом; відновлено явний порожній programs у prequalify за контрактом main, без зміни flat signing JSON.

Результати широкої регресії фіксуються після завершення процесу, а не за проміжним виводом. Попередні 780/808 успішних тестів зі старих баз не характеризують цей head. Docker runner використовує PHP 8.5.3 та окрему PostgreSQL 18 testing БД; робочі UAT-контейнери/дані не змінювались.

## Відкриті межі

1. Цей PR залишається draft до повторного повного domain прогону та успішного CI на його опублікованому head. Потрібна повторна перевірка після merge актуальних FHIR-передумов.
2. ServiceRequest #1006, MedicationRequest #1011 та Composition persistence #1042 ще потребують звірки з їхніми остаточними контрактами. Наявність старих DTO в #898 не доводить сумісності з усіма новими FHIR workflows. Зокрема, accepted-sign recovery та fresh native-detail signing не можна заміняти локальною UI-проєкцією.
3. Поточний DeviceRequest lifecycle/mapper з main, CarePlan approval/gate/quantity helpers поки залишені. Ціль видалення всього `Services/MedicalEvents` ще не виконана; її не можна досягати видаленням робочих сценаріїв main.
4. Browser UAT, реальний КЕП, live eHealth, конкурентні issuance/sign операції та повна application regression не підтверджені цією консолідацією.

Подальша робота ведеться в тій самій fork head-гілці та оновлює #898.
