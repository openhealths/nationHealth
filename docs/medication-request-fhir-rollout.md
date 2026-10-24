# 3.9: введення MedicationRequest FHIR profile

## Межі випуску

Структуровані дані та підпис використовуються у формах Encounter і CarePlan. Поточні поля максимальної разової та добової дози конвертуються в Quantity та Ratio лише з підтвердженою одиницею вибраного препарату; добова доза має denominator 1 day за значенням поля. Розширений редактор усіх варіантів Dosage нижче залишається окремою специфікацією. Не підставляти відсутні одиниці, route або timing.

FHIR profile застосунку не є готовим експортером довільного HL7 R4 MedicationRequest. ESOZ IL має власний transport; `toCreateRequestPayload` формує його окремо. Підпис завжди використовує native `$.data`.

## Схема і старі дані

1. Зробити резервну копію та перевірити відновлення до deployment. Нові міграції forward-only: down не звужує точність і не видаляє клінічні дані.
2. Застосувати install/update штатним механізмом застосунку. Update-only `finalize_medication_quantity_precision` потрібна, бо історична вереснева update-міграція на новому install звужує precision. Після обох етапів перевірити numeric_precision=24, numeric_scale=10.
3. Для конкретного пацієнта викликати `MedicationRequestRepository::preflight($personId)`. Метод тільки читає: `ready` або `importFullDetails` із localId, UUID і resourceType. Не включати персональні дані у загальний лог.
4. Для MRR отримати його повний patient-scoped detail; для MR — detail MR. Передати незмінений native detail у `importDetails($native, $personId, $resourceType)`. Метод перевіряє пацієнта, ідентичність, тип ресурсу і precision, записує у транзакції. List не є detail і не підходить для цього кроку.
5. Старі локальні неопубліковані чернетки, для яких немає remote detail, потребують повторного введення структурованих клінічних даних лікарем. Preflight не домислює їх і не робить автоматичний backfill. Зберігати оригінал до погодження повторного введення.
6. Повторити preflight. Не позначати всі записи готовими, якщо частина потребує detail/re-entry. Не запускати масову конвертацію неповних даних.

Нормалізовані Reference мають власні Identifier/CodeableConcept/Coding, впорядковані зв'язки та збережені display/version/userSelected. Dosage — складений FHIR datatype в окремому рядку з position та `fhir_data`. Цілі raw response не замінюють нормалізований ресурс. `ehealth_payload` не використовується для підпису.

## Специфікація frontend

Чинний frontend із main збережено. Форми створення з Encounter відкриваються на окремих patient-scoped сторінках. Дати використовують типовий datepicker. Повний редактор усіх варіантів структурованого Dosage нижче — подальше розширення, а не опис уже реалізованого UI.

### Створення з Encounter або CarePlan Activity

- Обидві форми передають `dosage_instructions` як повний масив Dosage; порядок зберігається. Додавання/видалення інструкції — явна дія. Sequence і text обов'язкові.
- Для кожної інструкції: patient_instruction; список additional_instruction CodeableConcept; site, route, method з правильними ESOZ довідниками. Не використовувати vaccination_routes.
- asNeeded: явний вибір boolean або CodeableConcept; `false` — значення, а не порожній input. Не заповнювати його автоматично при відкритті чужого ресурсу.
- Timing: event, repeat (bounds Duration/Range/Period, count/countMax, frequency/frequencyMax, duration/durationMax, period/periodMax, periodUnit, dayOfWeek, timeOfDay, when, offset), code. Зберігати всі невідредаговані компоненти. Нульовий offset не стирати.
- Dose: рівно один Quantity або Range. Rate: рівно один Quantity, Range або Ratio, якщо передано dose_and_rate згідно з IL schema. IL приймає один object на інструкцію: кілька FHIR doseAndRate не можна мовчки згорнути до першого.
- Quantity містить numeric value та явний unit; system/code надходять із вибраного довідника. Range містить обидві межі. Ratio містить numerator і denominator; denominator > 0. Не підставляти назву одиниці замість її code.
- `max_dose_per_administration` — Quantity; `max_dose_per_period` — Ratio; `max_dose_per_lifetime` — Quantity. Перші два обов'язкові у чинній IL Dosage schema. Наявні scalar поля не забезпечують такий контракт.
- Container dosage — окремий Quantity MEDICATION_UNIT. Заборонено fallback на PIECE, якщо код не вибрано.
- Період — календарні YYYY-MM-DD. Не робити UTC-конвертацію date-only полів.
- Для CarePlan показувати обидва зв'язки CarePlan/Activity; медпрепарат/програма та пацієнт мають відповідати Activity. Не надсилати UUID Activity як UUID CarePlan.

### Редагування / перегляд

- Відкривати повний `getResource`, передавати його як original resource у mapper; scalar preview не може бути джерелом повторного збереження.
- Непідтримуване поточною формою поле показати у read-only detail; воно має зберігатися при редагуванні інших полів.
- Явне очищення відрізняється від відсутнього поля. Не передавати empty string для кожного прихованого поля.
- Відображати всі інструкції, типи доз, одиниці, Ratio та повний Timing; кількість не обрізати до двох знаків.
- List preview із неповними даними позначати як summary; перед клінічною дією завантажувати detail. Legacy запис показує дію reimport/re-entry, а не успішний FHIR status.

### Підпис / переходи

- Перед КЕП backend робить свіжий MRR detail GET. Номер/дані для підтвердження мають стосуватися саме цього ресурсу, а не старого cache.
- При 401/403/404/422, неправильному patient/id/status або некоректному seed, якщо він присутній не показувати успіх і не запускати підпис.
- MRR SIGNED і MR ACTIVE — окремі записи з окремими UUID. Кнопки reject/resend/print/history мають передавати підтверджений MR UUID. Для непідтвердженого MR UUID дія заблокована.
- Pending/error/порожня відповідь не означає успіх. Не перемикати локальний стан на active/rejected оптимістично.
- Після невідомого результату мережевого запиту спершу refresh/reconcile; не робити автоматичний повтор КЕП чи create.

## UAT і відкриті розбіжності документації

Confluence prose згадує seed, однак опублікована response schema його не вимагає. У перевіреному preprod detail seed був відсутній; користувацький КЕП завершився успішним підписом. Guard перевіряє свіжі id/patient/NEW і повні обовʼязкові native поля; відсутній seed дозволено, присутній має бути непорожнім рядком. Native detail не модифікується й seed не генерується.

Обов'язкові сценарії перед злиттям umbrella:

1. Створення/підпис з Encounter і з CarePlan; позитивні приклади повної Quantity/Range/Ratio/Timing, OTP та OFFLINE.
2. Повторне відкриття/редагування без втрати додаткових coding і clinical fields.
3. Два лікарі/пацієнти/заклади: заборонені дії не змінюють локальні стани і не запускають signer.
4. Fresh seed змінюється між GET; КЕП підписує останній native detail, не cache. Реальний КЕП виконує користувач у контрольованому UAT.
5. Create → NEW MRR → Sign → SIGNED MRR + ACTIVE MR з різними id; reject використовує MR id. Одна клінічна кількість не рахується двічі.
6. Помилки 401/403/404/409/422, pending job та втрачена відповідь не породжують локального успіху; повторне узгодження не створює дублікати.
7. Upgrade існуючої БД і install+update порожньої БД дають однакову precision та не змінюють старі записи без explicit import.
8. Combined перевірка з umbrella 3.10 (#998) та 3.17 (#1006); shared primitive helper methods і guarded coding columns сумісні в обох порядках міграцій.

Модулі pharmacy dispense, standalone DeviceRequest і Medical Conclusions залишаються окремими. Успішні автоматичні contract tests не означають закриття всіх функціональних пунктів 3.9 або проходження сертифікації ЕСОЗ.

## Уточнення після combined-перевірки, 20.10.2026

Для перевірки кількості пов'язаний MR має перевагу над SIGNED MRR: це одна видана кількість із поточним статусом MR. Після EXPIRED або ENTERED_IN_ERROR MR попередня SIGNED заявка не повинна продовжувати займати кількість; незалежні MR враховуються окремо. Відсутність повного MR у локальному cache не є дозволом вигадувати його клінічний detail.

Перед підписом локальний Activity lookup обмежений пацієнтом, поточним планом (для CarePlan flow) і закладом. Посилання поза цим контекстом спричиняє відмову до КЕП та remote signing, замість пропуску перевірки залишку.

Сумісність із #998 потребує збереження цих правил у `quantityRecords`, а також сучасного fresh-detail signing при розв'язанні merge-конфлікту lifecycle. У спільному репозиторії залишається одна реалізація `quantityRecords`; старий підрахунок MRR плюс MR не повертати.

## Повний MR після підпису (24.10.2026)

Після підтвердження ЕСОЗ транзакція спочатку фіксує SIGNED MRR та окремий `active_id` MR. Потім patient-scoped GET MR завантажує повний native detail; `importDetails` зберігає повний FHIR profile. Коротка відповідь підпису не оголошується повним FHIR detail.

Якщо GET або import не вдався, acknowledgment не відкочується. `fhir_metadata.signedPrescriptionPending` є службовим станом і не потрапляє у клінічний `getResource`. У реєстрі пацієнта «Синхронізувати підписані рецепти» повторює тільки GET/import для свого пацієнта після `medication_request:read` authorization. Повторний КЕП/PATCH/create не виконується. Idempotent import не реактивує вже завершений/відхилений/прострочений/заблокований MR. Неповні дані не можна використовувати як канонічний ресурс до успішної синхронізації.

Календарні поля MRR `created_at`, treatment та dispense dates не переводяться у UTC як timestamp. Пошук взаємодій за локальним днем використовує UTC межі відповідного дня з урахуванням DST. Нічний preprod кейс `Created_at must be within the encounter period` потребує окремого позитивного UAT; не обходити його backdate або вигаданими датами.
