# Модуль 3.9: MedicationRequest — дизайн переходу

Issue: https://github.com/openhealths/nationHealth/issues/1008

Початкова база дизайну: main `39d4a6b7ca06317b762b69f666fca3225fb9536b`. Release/review stack перебазовано на company main `b70debf1` 24.10.2026; початкові джерела нижче збережені як історія контракту.

## Контракти та джерела

Перевірено 19.10.2026: [PROD OpenAPI GENERAL MIS 9.15.3](https://docs.ehealth.gov.ua/scalar-docs/general-mis.json). Перевага цьому актуальному контракту над застарілими Apiary прикладами.

- [Create MRR, API-005-044-0002](https://e-health-ua.atlassian.net/wiki/spaces/ESOZ/pages/17570693379), версія сторінки 51, 15.10.2026.
- [PreQualify MRR](https://e-health-ua.atlassian.net/wiki/spaces/ESOZ/pages/17570660609), версія 42, 15.10.2026.
- [Get MRR Details](https://e-health-ua.atlassian.net/wiki/spaces/ESOZ/pages/17570529446), версія 37, 15.10.2026.
- [Sign MRR](https://e-health-ua.atlassian.net/wiki/spaces/ESOZ/pages/17570824338), версія 39, 18.10.2026.
- [Get MR Details](https://e-health-ua.atlassian.net/wiki/spaces/ESOZ/pages/17570103587), версія 38, 15.10.2026.
- [Reject MR](https://e-health-ua.atlassian.net/wiki/spaces/ESOZ/pages/17570758873), версія 31, 15.10.2026.

Дати вище — час редакції сторінки Confluence, а не обіцянка дати впровадження API. Зберігати native API casing і форму запитів: IL використовує `medication_id`, `medication_qty`, `dosage_instruction`, `dose_and_rate` як object. Замінювати цей transport загальним HL7 JSON не можна.

## Спільне представлення

Використати існуючий MedicationRequestMapper. `toFhir` / `fromFhir` описують внутрішній ESOZ FHIR profile з Reference, CodeableConcept, Quantity, Ratio, Range, Timing і Dosage. Це профіль застосунку для ESOZ, а не твердження про валідацію незміненим HL7 R4 validator: ESOZ має власні статуси заявок та додаткові поля `program`, `division`, `period`, `containerDosage`, `informWith`.

- Medication UUID — typed `medicationReference`; patient — `subject`, author — `requester`, Encounter — `context`. Відмовитися від старого `requester.agent` і UUID препарату як термінологічного коду.
- Зберігати всі `basedOn` з типами та порядком: CarePlan і Activity мають різні ідентичності. Не визначати Activity за першим елементом.
- Не генерувати UUID, поточну дату, route, одиниці, текст, asNeeded або знаменник Ratio у mapper. Нові значення створює тільки явна команда користувача / workflow.
- Повні Dosage: додаткові інструкції, site, route, method, Timing, обидва asNeeded варіанти, dose Quantity/Range, rate Quantity/Range/Ratio, всі максимальні дози. `false` і `0` не видаляти.
- Повторне редагування форми застосовувати над переданим оригінальним `resource`; непредставлені поля не втрачати. Явне очищення має окрему семантику від відсутнього поля.
- Календарні `created_at`, `started_at`, `ended_at` не перетворювати між часовими поясами. Неповні / неоднозначні старі дозування блокувати перед відправленням, а не домислювати.
- Native IL ↔ profile адаптери повинні зберігати всі відомі поля та metadata; підписаний native snapshot ніколи не проходить через mapper.

## Зберігання

Існуючі MedicationRequestRepository та моделі залишаються точкою входу. Нові сервіси для атомарних завдань не потрібні.

Репозиторій володіє транзакціями, patient-scoping, блокуванням ідентичності, записом та вибірками. Reference зберігати з усіма coding у впорядкованих owned зв'язках; використовувати ті самі primitive helper methods, що в 3.10/3.17, без залежності від їхніх незлитих гілок. Dosage зберігати як повний складений FHIR datatype в окремому рядку з позицією; оновлення за позицією не має видаляти незмінені рядки. Застарілі проєкції зберігати для чинних читачів.

Кількість — decimal(24,10), без округлення до двох знаків. Дані поза підтримуваною точністю відхиляти до запису. Додаткова metadata зберігається окремо від нормалізованих полів. `ehealth_payload` — лише native cache для відображення/діагностики, не джерело підпису.

List import лишається partial: він не стирає клінічні поля повного ресурсу й не перетворює MRR на MR. Для перезапису повного клінічного ресурсу потрібен явний detail import. Ідентичності MRR і виданого MR не підміняти одна одною.

## Workflow та підписання

Інтеграція — у `app/Classes/eHealth`, наявний lifecycle залишається сумісним фасадом для caller-ів. Перед КЕП зробити GET `/api/persons/{person_id}/medication_request_requests/{id}`; перевірити resource id, patient id, NEW status і повноту native деталей; підписати рівно свіжий `$.data`. Немає fallback на list, cache чи локальну реконструкцію. Після 401/403/404/422, помилкового id/person, неповних деталей, некоректного seed (якщо його повернуто) або недопустимого статусу signer і PATCH не викликаються.

Create/Sign/Reject не переводять локальний запис у кінцевий стан на порожній / pending / error відповіді. Після Sign MRR стає SIGNED; окремий MR має свій id і ACTIVE. Подальші дії MR використовують тільки підтверджений id MR.

## Три блоки для рев'ю

1. Mapper/profile/native адаптери + synthetic contract tests.
2. Нормалізоване зберігання, guarded forward migration, preflight / правила старих даних + repository tests.
3. Підпис / endpoint cutover, сумісність CarePlan, UI specification / runbook + workflow tests.

Одна company umbrella draft PR та три review PR у персональному fork. Кожний наступний блок базується на попередньому. Не зливати автоматично.

## Перевірка і межі

Sail, окрема тимчасова БД testing; DatabaseTransactions, synthetic fixtures, Http::fake, без реальних КЕП/пацієнтів. Перевірити native round trip, false/zero, багато Dosage/codings/references, Range/Ratio/Timing, точність, повторний запис, partial import, cross-patient rejection, fresh signing і failure-before-signer. Сумісність з 3.10/3.17 перевірити окремим непублікованим combined checkout.

Frontend не реалізується: специфікація повинна описати введення структурованих одиниць/Ratio, відображення повних дозувань, legacy warnings, reimport та окремі стани MRR/MR. Реальна UAT/КЕП і рішення щодо міграції старих даних — release gates, які тести не замінюють.

Поза цим модулем: pharmacy dispense 3.5; standalone DeviceRequest; Medical Conclusions 3.8; OAuth/scope/refresh/shared-job refactor; решта функціонального аудиту, не потрібна для переходу FHIR.


## Доповнення 24.10.2026: часові пояси та межа календарної дати

### Цільовий контракт

1. Момент часу (`instant`, повний `dateTime`, межі клінічного Period) зберігати в UTC. Для API віддавати ISO 8601 із `Z`; явний offset у вхідних даних зберігає момент часу, а не його текстове представлення. SQL-рядок без offset у канонічному timestamp-полі означає UTC. Не дописувати `Z` до локального часу без конвертації.
2. Відображення та введення локального часу визначає `APP_DISPLAY_TIMEZONE`, IANA timezone: `Europe/Kyiv` за замовчуванням, можливі `Europe/Berlin`, `America/New_York` тощо. Не використовувати фіксоване `+02:00`/`+03:00`: offset залежить від дати. Налаштування ринку не змінює момент часу в клінічному ресурсі або БД. Новий timezone застосовується після перебудови config cache та перезапуску довготривалих workers під час розгортання.
3. Календарні `date` (`created_at`, `started_at`, `ended_at` в IL-рецепті, дата народження, date-only строк придатності) не переводити між часовими поясами. Поле `created_at` рецепта в API — не те саме, що технічний `created_at` Eloquent. Календарна дата не є UTC-midnight timestamp.
4. Локальний день у SQL — напіввідкритий інтервал `[localStart->UTC, nextLocalStart->UTC)`. Наступний календарний день обчислюється в локальній зоні перед конвертацією, а не додаванням 24 годин в UTC. Це дає коректні 23-/25-годинні дні переходу DST.
5. Клінічний ресурс не відновлювати з локалізованого рядка для відображення: він може втрачати секунди й offset. Використовувати raw canonical timestamps / repository `toResource()`. КЕП підписує свіжий native snapshot без повторного перерахунку дат.

### Що змінено зараз

- Налаштування `app.timezone`, яке зараз одночасно обслуговує PHP clock і legacy UI, читає `APP_DISPLAY_TIMEZONE` з fallback `Europe/Kyiv`. Це сумісний перехідний крок; глобальний PHP clock ще не переведений на UTC.
- `convertToEHealthISO8601()` явно трактує локальний input у налаштованому поясі та конвертує в UTC; input з явним offset зберігає свій instant. `convertToLocalTimezone()` трактує native timestamp без offset як UTC й переводить у налаштовану зону.
- `MedicationRequestRepository::eligibleEncounters()` застосовує UTC-межі локального дня замість `whereDate(end, today())`; перевірки пацієнта, статусу та виконавця зберігаються.
- Уточнено переклад помилки періоду взаємодії: сама відмова ESOZ не доводить, що взаємодія стара або не сьогоднішня.
- Підготовлено регресійні сценарії Києва, Берліна, Нью-Йорка, UTC та двох переходів DST. PHPUnit у цьому сеансі не запускався.

Це ще не повний перехід усього проєкту на UTC. Налаштування timezone саме по собі не мігрує SQL-дані, не відокремлює всі date-only поля й не робить кожен екран сумісним з іншим ринком. У Employee лишаються прямі `Europe/Kyiv`; вони поза поточним клінічним виправленням.

### Наступний блок: однорідне зберігання без зміни клінічних дат

Після класифікації полів розділити `app.timezone = UTC` для runtime/storage і `app.display_timezone = env('APP_DISPLAY_TIMEZONE', 'Europe/Kyiv')` для UI/локальних календарних правил. Перемкнути їх разом із відповідними caller-ами в одному review-блоці; не міняти default на UTC до цього переходу.

1. Для CarePlan/Activity, ServiceRequest, MedicationRequest і Composition перелічити всі date-only, instant/dateTime, technical audit timestamps і поточні типи SQL. Для кожного входу встановити source zone: явний offset, native UTC чи local UI. `EHealthTimestampCast` використовується також на date-only полях, тому глобальна заміна setter/getter зараз небезпечна.
2. Timestamp-поля читати/записувати в UTC через наявні repositories; `PeriodRepository::syncExact()` / `toResource()` — існуючий приклад. Технічні Eloquent timestamps потребують окремого переходу на UTC, а не лише зміни JSON serialization. Всі date-only поля зберігати як дату за контрактом, без timezone cast.
3. UI та локальні правила `now()/today()`, форми, фільтри, validation і друк явно використовують display timezone. `datetime-local` не містить timezone: конвертація відбувається на серверній межі форми. Відсутній час у весняному DST відхиляти; неоднозначний час осіннього переходу потребує явно визначеного offset, а не мовчазного вибору.
4. Не зсувати історичні записи одним масовим UPDATE: клінічні Period уже UTC, а деякі технічні timestamps зараз локальні. Для нових записів встановити однорідний контракт; для історичних — окремий план лише за потреби з відомим походженням. Підписані payload-и не переписувати.
5. Перевірити round trip «local input → API UTC → canonical DB → local UI», збереження секунд, явні offsets, зміну display timezone без зміни raw даних, опівніч, DST і незмінність date-only. Потім браузерний UAT обох типів закладу; КЕП виконує користувач.

### Окремий незакритий контракт ESOZ: дата створення рецепта

Фактичний кейс: о 00:41 Києва 24.10.2026 UTC-період нової взаємодії має дату 23.10, а API `created_at` як календарна дата — 24.10. Локальний пошук дня був некоректним і виправлений. Водночас відмова `Created_at must be within the encounter period` потребує окремої перевірки семантики API.

Поточна [PROD-документація Create Medication Request Request](https://e-health-ua.atlassian.net/wiki/spaces/ESOZ/pages/17570693379/REST+API+Create+Medication+Request+Request) визначає `created_at` як DATE, встановлений зовнішньою системою, і не задає для нього UTC timezone. Немає підстав називати `now('UTC')->toDateString()` універсальним виправленням: після UTC-опівночі та сама локально сьогоднішня взаємодія з UTC-періодом попереднього дня знову може не збігтися. Кандидатну заміну прибрано; дата рецепта не підмінюється датою взаємодії.

Потрібно звірити точний period із native detail ESOZ і підтвердити їхнє правило зіставлення DATE з datetime-period, включно зі сценаріями до та після UTC-опівночі. До цього відмова для нічної взаємодії не вважається виправленою. Загальні правила [FHIR date/instant](https://hl7.org/fhir/R4/datatypes.html) пояснюють різницю типів, але не замінюють цей native IL-контракт.


## Уточнення 24.10.2026: seed у підписанні MRR

Фактичний fresh GET для заявки з Encounter у СМД повернув правильні id, patient і NEW, але не повернув seed. PREPROD General MIS 9.15.9 GET details (operation-0147) і `Medication_request_Request_Sign_DecodedPayload` (component-0540) не описують seed і не включають його до required. Текстове зауваження на сторінці Sign суперечить цим схемам; не переносити його як безумовний локальний required validator.

Зберігається свіжий GET без cache fallback та без deprecated endpoint fallback. Перед КЕП перевіряються id, patient, NEW, наявність полів повного signing payload, medication_info та dosage_instruction. Відсутній seed не домислюється й не додається. Якщо seed повернуто, він має бути непорожнім рядком і передається в незміненому native snapshot. Скорочений list snapshot не проходить перевірку навіть із seed. При продовженні вже створеної заявки перед відкриттям КЕП виконується read-only preflight. Перед самим підписом GET виконується знову, щоб попередній preflight не став застарілим джерелом підпису. Одразу після успішного POST create зайвий preflight GET не додається.

Encounter UI після невдалої спроби зберігає існуючий locked request UUID та пропонує продовжити підписання. Клінічні fieldsets блокуються до завершення цієї заявки, щоб форма не показувала нові значення під час підписання старого snapshot. Повторна спроба в цій формі не виконує POST create ще раз; preflight виконує лише GET. Остаточний КЕП виконує користувач.
