# ТВ39: електронні направлення, #1202

База: затверджена редакція 39 (наказ НСЗУ №464 від 25.08.2026), upstream/main `3ae4e34e3631ed4bba711c3b4ab6d96d701e0cc5`. Частина umbrella #1201. Проєкт редакції 40 не застосовувався.

## План і результат

| ID | Вимога ТВ39 | Реалізація та перевірка |
| --- | --- | --- |
| R01 | 3.17.1.3.2–3.3: hospitalization specialty та допустимі optional references | Спеціальність зберігається для hospitalization/transfer_of_care у формі, чернетці та підписаному payload; required validation діє до API. `ReferralTv39WorkflowTest`, `ServiceRequestFhirApiTest`, `ServiceRequestMapperTest`. |
| R02 | 3.17.1.3.3.1: лабораторне направлення на зразок без взаємодії | Окремий `SpecimenReferral` з картки зразка; focus/specimens, допустима лабораторна послуга/група, Person/Preperson, author policy, редагування того самого draft, KEP field errors. `ReferralTv39WorkflowTest`. |
| R03 | 3.17.2.3–3.1: читання у specimen context без розкриття особи | Клієнт GetBySpecimen, повна валідація усіх сторінок до UI; результат з whitelist полів без subject; контекст та доступ перевіряються перед API. `ServiceRequestContractTv39Test`, `ReferralTv39WorkflowTest`. |
| R04 | 3.17.1.3.4: permitted_resources із grounds/supporting info | Репозиторій визначає пов’язані епізоди/діагностичні звіти для Condition/Observation/DiagnosticReport лише поточного пацієнта; mapper передає references до підписання. `ReferralTv39WorkflowTest`. |
| R05 | 3.17.1.5: PreQualify незалежно від програми | PreQualify викликається перед підписанням направлення з призначення навіть без програми. Вибрана програма мусить мати verdict; INVALID зупиняє persistence. Чернетки зберігаються без RPC. `ReferralLifecycleTest`. |
| R06 | 3.17.1.3.2.1: дозволені категорії ASSISTANT | Policy перевіряє фактичну категорію сервісу/всіх member services групи з validated catalog; підміна category не обходить guard; невідома конфігурація забороняє. `ReferralTv39WorkflowTest`. |
| R07 | 3.17.3.3: DOCTOR не виконує counselling | Policy use перевіряє обраного active/APPROVED linked employee, поточний заклад, роль і категорію перед Qualify/Use. Allowed SPECIALIST та denied DOCTOR перевірено. `ReferralTv39WorkflowTest`, `ReferralExecutorPhase4Test`. |
| R08 | 3.17.3.3.1–3.2: вибір/заміна/скасування програми | У модалі Use є selector service-програми; підписаний вибір пріоритетний, null явно очищає стару програму; повторний Qualify, rejection лишає можливість змінити вибір. `ReferralExecutorPhase4Test`, `ReferralTv39WorkflowTest`. |
| R09 | 3.17.3.5: фактичний виконавець | Реєстр дозволяє вибрати eligible employee; incoming workflow використовує обраного performer взаємодії, автоматичний вибір лише за одного eligible кандидата; вибір не підміняється first(). selected usedBy refs зберігаються лише після підтвердження. `ReferralExecutorPhase4Test`, `ReferralTv39WorkflowTest`. |
| R10 | 3.17.3.6: повторне Use іншим закладом | Policy перевіряє чужий usedByLegalEntity та останній matching history.inserted_at з офіційним SERVICE_REQUEST_REUSE_AFTER_MINUTES; до інтервалу/без history/config діє denial. Керування власним використанням відділено від повторного Use. `ReferralTv39WorkflowTest`. |
| R11 | 3.17.4.2–2.1: фактична послуга підтвердженого ЕМЗ | За програмою eligible completion EMZ мусить містити точну послугу або member service направленої групи; same patient/LE, registered status, incoming reference, read policy і known episode ownership перевірено повторно перед RPC. `ClinicalReferralCompletionTest`. |
| R12 | 3.17.4.5: попередження про залишок | Реєстр/картка/взаємодія показують remaining quantity та checkbox; сервіс повторно читає validated актуальні дані і вимагає acknowledgement до completion RPC; pending не видається за completed. `ClinicalReferralCompletionTest`, `ReferralExecutorPhase4Test`. |
| U05 | ПМД 3.2.2.15; СМД 3.3.1.19: перегляд ЕМЗ погашення видавцем | Read-only modal Encounter/Procedure/DiagnosticReport за completedWith; issuer LE, patient identity, type/read scope перед API; full response identity перевіряється до UI, чужі EMЗ не імпортуються в локальну БД. `ReferralTv39WorkflowTest`. |
| U06 | 3.3.4.1.3; 3.17.1.15: refusal/стаціонар/переведення | Збережено AMB/service_delivery_location/new episode для refusal, actual server rule забороняє existing episode. SPECIALIST INPATIENT дозволено transfer/lab для Person/Preperson; counselling лише Person discharge. Preperson має окремий FK, маршрути/context/selection/import/complete перевіряють тип пацієнта, numeric ID collision не перетинає records. `ReferralTv39WorkflowTest`. |
| G01 (R) | API contracts та response-before-consumption | ServiceRequest resource/list/specimen/qualify/prequalify/receipt/SMS, nested service dictionary та Division search мають валідатори. Delegation зберігає validator; перед mapper/storage/UI/cache застосовується validate(). Final submission перевіряє всі native ресурси списку до вибору/merge; malformed result залишає pending без мутацій. Окремо підтримано документовані status-only acknowledgements. `ServiceRequestContractTv39Test`, `ServiceRequestSubmissionTest`, `DivisionSearchContractTest`. Спільні DeviceDefinition reads належать G01 umbrella. |
| G02 (R) | 1.1.7: RFC4122 UUID першої версії | Нові клінічні referral ID утворюються `Uuid::uuid1()`; старі UUID під час редагування зберігаються, UI/cache keys не змінюються. `ReferralTv39WorkflowTest`. |

## Продуктові сценарії BA03/BA04/BA07/BA08

BA03/BA07 використовують спільний provider пов'язаних клінічних документів. Таблиця нижче описує частину направлень; приймання інших модулів належить umbrella #1201.

| Точка входу | Поведінка направлень | Перевірка |
| --- | --- | --- |
| Взаємодія | Створення з точним поточним автором і контекстом; перегляд зареєстрованого направлення через окремий read-only маршрут із policy. Для BA08 — пошук наявного ПЛ, вибір призначення, запит/перевірка approval конкретного виконавця, native залишок і одиниці, перевірка актуальних зв'язків перед та після KEP. | `EncounterDocumentPagesTest`, `EncounterReferralPlanLinkTest`, `ReferralRegisteredActionsTest`. |
| Картка пацієнта / призначення ПЛ | Читання й друк потребують права читання; відкликання/помилка перевіряють поточну роль, scope, заклад видавця та active стан. Підпис із реєстру повторно перевіряє source encounter і актуальний доступ до призначення. Signing field errors лишають модаль відкритою. | `ReferralRegisteredActionsTest`, `EncounterReferralPlanLinkTest`, `ReferralTv39WorkflowTest`, `PatientReferralRegistryUatTest`. |
| Загальний реєстр за точним номером (BA04) | Дії перегляду/друку/відкликання/помилки застосовуються до точного UUID. Перегляд веде до доступного джерела encounter/ПЛ або картки; незбережений результат проходить повну перевірку identity перед імпортом. Terminal стан зберігає перегляд/друк, а mutation дії доступні лише за відповідною policy. | `ReferralRegisteredActionsTest` перевіряє native результат, HTML меню, допустимий шлях і відмову без SQL/API/queue/signing. Browser suite перевіряє реальний dropdown і Livewire аргументи. |

Provider ПЛ з `12781f70` та selected-actor helper з `e07fc925` інтегровані без заміни encounter author історичним автором ПЛ. BA02 capacity враховує підтверджені направлення за distinct UUID і вже створені призначення; draft/pending не збільшують capacity. Відсутній або прострочений verified approval блокує створення й підписання.

## Джерела контрактів

Локальний офіційний архів ЕСОЗ `ehealth-documentation/openapi/preprod/medical-events.json` і `general-mis.json`, знімок 22.10.2026, та відповідні `references/preprod` сторінки Get Service Request Details, Get Service Requests in Specimen Context, Qualify/PreQualify/Use/Complete, Get Services Dictionary, Search Divisions. Деталі ресурсу, `used_by_legal_entity_history`, category/service group membership, identity/ref/envelope/paging правила звірені до реалізації. Medical Events paging використовує `page`, General MIS — `page_number`.

## Інтеграція та приймання

Потрібні обидві install/update migration pairs: `2026_11_11_130000_add_preperson_to_service_requests` і `2026_11_11_160000_complete_service_request_execution_storage`; не запускати install+update разом для нового встановлення.

Runtime mapping: `ASSISTANT_SERVICE_REQUEST_ALLOWED_CATEGORIES` → `ehealth.referrals.assistant_allowed_categories`; `SERVICE_REQUEST_REUSE_AFTER_MINUTES` → `ehealth.referrals.reuse_after_minutes`. Невідомі значення не замінено вигаданими defaults. Для реального приймання потрібні значення відповідного середовища ЕСОЗ.

Автоматизовані тести виконуються тільки в ізольованій `tv39_1202` із спеціальним bootstrap; `mis_dev` та shared `testing` не змінювались. Реальні KEP/SMS і віддалений ЕСОЗ не запускались. Ручна перевірка підписання й віддалених acceptance outcomes залишається етапом інтеграційного UAT користувача, а не доказом автоматизованих тестів.

## Підсумок перевірок

Фінальний broad run: **357 tests / 2057 assertions PASS**, одна наявна deprecation. Перевірено весь `tests/Feature/Referral`, реєстр, API controllers, standalone encounter pages, incoming state, Division lookup, patient referrals і referral mapper/lifecycle/signing/printout suites. У CI додано Division contract suite та EncounterReferralStateTest; нові feature suites входять через referral directory.

Pint: **61 touched PHP/Blade files PASS**. `git diff --check` PASS. Перевірено імпорти, repository factories, нові policy calls та переклади feedback.

`PatientReferralDateFiltersTest` і `PatientReferralRegistryUatTest` мають власну вимогу literal database `testing`; їхні guards збережено. Повторний запуск цих двох suites виконує umbrella orchestration у підготовленій ізольованій конфігурації, не в `tv39_1202`.

## Актуалізація main та дат роботи

Umbrella оновлено до upstream/main `c1052b115ba53da2ab43bbb86d144452047815ea` merge commit `7bf33601`: збережено cancellation synchronization, нове видалення чернеток і canonical Person/Preperson routes. Import/FQN audit охоплює всі PHP/Blade файли цього merge.

U01/R07: policy перевіряє фактичні дати обраної посади: start_date null або <= today, end_date null або > today. Майбутня або завершена сьогодні посада з застарілим active прапором не дозволяє Create/Use/CreateCode. Перевірка: `ReferralEmployeeEmploymentPolicyTest`, матриця ПМД/СМД та DOCTOR/SPECIALIST/ASSISTANT/MED_COORDINATOR/MED_ADMIN/LABORANT; відмова specimen create не змінює заявки та не викликає API.

Перевірка доповнення: **78 tests / 456 assertions PASS**, 1 existing PHPUnit deprecation, ізольована база `tv39_1202`. Import/FQN audit **80 merge PHP/Blade files PASS**, Pint **6 final PHP/layout files PASS**, `git diff --check` PASS. У двох великих Blade збережено попереднє форматування; зміни обмежено імпортами й короткими class references.
