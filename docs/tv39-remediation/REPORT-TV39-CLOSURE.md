# Звіт про закриття прогалин ТВ39

Дата: 11 листопада 2026 року. Статус: інтеграція та остаточне приймання тривають.

Підстава — затверджена редакція 39. Проєкт редакції 40 не використовувався. Початковий аудит містив 46 доробок, окрему нормативну розбіжність C01 та перевірки U01–U08. Користувач додав дев’ять пунктів BA01–BA09 після розмови з бізнес-аналітиком і дизайнером. База аудиту: `3ae4e34e3631ed4bba711c3b4ab6d96d701e0cc5`; інтеграційна гілка: `Mefizz/ohealth:i1201_tv39_clinical_umbrella`.

## Організація робіт

| Блок | Issue | PR | Докладні докази |
| --- | --- | --- | --- |
| Спільні контракти, ролі, інтеграція | #1201 | #1217 (draft) | `shared-response-contracts.md`, `nursing-and-summary.md`, `clinical-identifiers.md` |
| Плани лікування та призначення | #1196 | #1197 оновлено; джерело #1211 | `care-plan.md`; owner checkpoint `a671dd3c` інтегровано |
| Електронні направлення | #1202 | #1214 | `referrals.md` |
| Електронні рецепти | #1203 | #1209 | `prescriptions.md` |
| Е-запити | #1205 | #1208 | `device-requests.md` |
| Скасування взаємодії та ЕМЗ | #1206 | #1207 | `encounter-cancellation.md` |
| Медичні висновки | #1204 | #1218 | `compositions.md`; followups `4a039cf6` та `0eff203c` інтегровано |

Розробку передано п’ятьом окремим локальним чатам із власними контекстами та визначеними робочими каталогами: плани лікування, направлення, рецепти/е-запити, медичні висновки, спільні контракти/права/інтеграція. Тут координуються залежності, перенесення commits і остаточна регресія. Злиття в main та розгортання не виконувались.

Для всієї ділянки користувач додатково встановив: жодних `final` класів; посилання на класи через `use` або Blade `@use`; виклики репозиторіїв через `Repository::...()` entry points. Аудит охоплює існуючі рядки у відповідних PHP/Blade файлах і тести.

## Перелік 46 доробок

«Реалізовано» означає наявний код і позитивні та негативні автоматизовані перевірки. Це не означає підтверджений реальний підпис, доставку SMS або прийняття продуктивною ЕСОЗ. Номери вимог відтворені з початкового аудиту.

Після включення нового `main` та посилення перевірки чинних ролей широкі прогони виявили незавершені випадки. CI `4bea6c96` перевірив 2564 tests / 11102 assertions: 56 errors і 79 failures. Розподіл передано власникам: CP 32, направлення 24, рецепти/е-запити 31, МВ 39, спільні Episode fixtures 9. Частина належить ще не включеним owner commits. Раніші результати нижче є історичними доказами окремих прогонів; остаточне закриття залежить від успішного прогону завершеної інтеграційної гілки.

На прохання користувача нові CI-запити, watch, завантаження логів/артефактів та перезапуски призупинено. Дослідження вже збережених фейлів перенесено в окремий чат `01a1cb33-f47f-7cd0-92be-93dd676daed2`. Нижче наведено локальні перевірки; історичний CI не є результатом поточної гілки.

| ID | ТВ39 | Що виправлено | Перевірка / стан |
| --- | --- | --- | --- |
| G01 | 3.10.1.2; 3.17.1.3; 3.20.3.1.2 | Повні native response-контракти до mapping, БД, UI, підпису та рішення про завершення; перевірка collection/paging/job та контексту пацієнта | Контрактні й boundary suites; останні альтернативні device/referral гілки проходять завершальну перевірку |
| G02 | 1.1.7 | UUIDv1 для нових клінічних документів; видані ідентифікатори збережено | `ClinicalIdentifiersTv39Test`, actual CarePlan draft save/edit; реалізовано |
| CP01 | 3.10.1.1; 3.10.2.1; 3.10.1.4.2 | MED_COORDINATOR серед допустимих авторів, менеджерів і одержувачів доступу; чинна прив’язана посада та scopes | `UserGetCarePlanWriterEmployeeTest`; реалізовано |
| CP02 | 3.10.1.2.1 | Автор ПЛ дорівнює фактичному виконавцю обраної завершеної взаємодії | Writer/draft/signing tests, інший або неактивний автор; реалізовано |
| CP03 | 3.10.1.2 | basedOn/partOf: вибір, чернетка, повторне відкриття, очищення та signing references | `CarePlanTv39ContractTest`; реалізовано |
| CP04 | 3.10.3.1 | based_on, part_of, period_date та encounter UUID у пошуку; всі validated сторінки | `CarePlanTv39SearchTest`; реалізовано |
| CP05 | 3.10.3.2.1 | Дозволені short_name/age у пошуку за номером без локальної картки пацієнта | Contract/search tests, жодного protected detail read; реалізовано |
| A01 | 3.10.2.7.4; 3.10.2.7.4.1 | Додатна ціла кількість послуг при введенні та перед підписом | Invalid/valid service quantities; реалізовано |
| A02 | 3.10.2.7.3 | Перелік із усіх допустимих упаковок; межа max_daily_count × включні дні; повторна перевірка свіжого каталогу | `CarePlanTv39ContractTest`, `DeviceDefinitionContractTest`; реалізовано |
| A03 | 3.10.2.9.2 | Призначення без програми може не мати period; частково заданий period відхиляється | Activity contract/form tests; реалізовано |
| A04 | 3.10.2.4.1; 3.10.2.7.4.1 | Обов’язкові кількість і допустима MEDICATION-програма для лікарського призначення | Missing/wrong/valid program та signing tests; реалізовано |
| R01 | 3.17.1.3.2; 3.17.1.3.3 | Спеціальність hospitalization і допустимі performer/location references у payload | Referral workflow/API/mapper suites; реалізовано |
| R02 | 3.17.1.3.3; 3.17.1.3.3.1 | Лабораторне направлення зі зразка без попередньої взаємодії, Person/Preperson | `ReferralTv39WorkflowTest`; реалізовано |
| R03 | 3.17.2.3 | Пошук і читання направлень у specimen context з дозволеним набором даних | `ServiceRequestContractTv39Test`, workflow tests; реалізовано |
| R04 | 3.17.1.3.4 | permitted_resources із reason/supporting info тільки належного пацієнта | Repository/mapper/workflow tests; реалізовано |
| R05 | 3.17.1.5 | PreQualify призначення ПЛ також без програми; INVALID блокує підписання | `ReferralLifecycleTest`, prequalify boundary tests; реалізовано |
| R06 | 3.17.1.3.2.1 | ASSISTANT обирає тільки дозволені конфігурацією категорії послуг; категорія перевіряється за каталогом | Tampered category/group/config tests; код реалізовано, потрібна конфігурація середовища |
| R07 | 3.17.3.3 | DOCTOR не бере counselling в роботу; перевірка чинного обраного виконавця | Workflow/executor allowed/denied tests; реалізовано |
| R08 | 3.17.3.3.1; 3.17.3.3.2 | Вибір/заміна/очищення програми перед Use та повторний Qualify | Executor/workflow tests; реалізовано |
| R09 | 3.17.3.5 | Явний вибір eligible виконавця; автоматичний вибір тільки за одного кандидата | Multiple employee/use/incoming tests; реалізовано |
| R10 | 3.17.3.6 | Повторне використання іншим закладом після офіційного інтервалу; перевірка history | Reuse-before/after/missing-history tests; код реалізовано, потрібна конфігурація середовища |
| R11 | 3.17.4.2; 3.17.4.2.1 | При погашенні відповідність фактичної послуги ЕМЗ, статусу, пацієнта, закладу та incoming reference | `ClinicalReferralCompletionTest`; реалізовано |
| R12 | 3.17.4.5 | Видимий залишок і явне підтвердження перед погашенням; повторна перевірка сервісом | Completion/executor tests; реалізовано |
| D01 | 3.10.2.9.2; 3.20.4.3 | Е-запит із безпрограмного призначення без period; межі ПЛ і заданого period збережено | `DeviceRequestTv39RemediationTest`; реалізовано |
| D02 | 3.20.3.1.4; 3.20.3.1.6 | КЕП-модаль і поля залишаються після помилки; concurrent submission блокується до ефектів | Actual Livewire field/lock tests; реалізовано |
| W01 | 3.2.2.7.2; 3.2.2.7.2.1; 3.3.1.10; 3.3.1.10.1; 3.11.4.5; 3.11.4.6 | Перед скасуванням повний validated пакет 11 типів ЕМЗ та пов’язаних діагнозів; атомарна синхронізація | Cancellation pagination/submission suites; реалізовано |
| W02 | 1.1.4.1; 1.1.4.2; 3.11.4.2 | Pending/receipt не змінює клінічний статус; durable job та підтверджене фінальне завершення | Pending/failed/processed/resume/worker/uncertain tests; реалізовано |
| MV-01 | 3.8.2.4; 3.8.2.5.2; 3.8.2.6 | Вибір іншої непрацездатної особи для догляду, її auth methods та Person/Preperson правила | Native subject/focus, eligibility та preflight tests; код інтегровано, реальне приймання окремо |
| MV-02 | 3.8.1.9; 3.8.2.11 | Пошук за focus з результатами іншого subject без втрати доступного висновку | Full-native focus/search/storage tests; код інтегровано, дві старі registry fixtures завершуються |
| MV-03 | 3.8.2.15.1; 3.8.2.15.2 | Заборона простроченого скасування за чинною конфігурацією ЕСОЗ | Configured cancellation boundary tests; код інтегровано |
| MV-04 | 3.8.1.5.1; 3.8.2.5.1 | Усі сторінки взаємодій та точний допустимий автор | Pagination/current-role/own-encounter tests; код інтегровано |
| MV-05 | 3.8.1.5.2.1; 3.8.2.5.5 | Загальні EMAL configuration rules до створення/скасування, без вигаданих defaults | 77-key explicit mirror і свіжий validated eligibility read; код інтегровано, значення цільового середовища та OFFLINE acceptance відкриті |
| RX-01 | 3.9.4.1.3; 3.9.4.1.4 | Повні detail pages заявки й рецепта з фактичними клінічними даними | `TV39PrescriptionRemediationTest`; реалізовано |
| RX-02 | 3.9.3.16; 3.9.3.17.2; 3.9.4.1.6; 3.9.4.1.7; 3.9.4.2.1; 3.9.4.2.2 | Повторне відкриття NEW, підпис, відхилення, друк і SMS поза ПЛ | Durable draft/lifecycle/Livewire tests; реалізовано |
| RX-03 | 3.9.4.1.1 | Повні фільтри і всі сторінки MR/MRR; атомарний імпорт | Multi-page/invalid-later-page tests; реалізовано |
| RX-04 | 3.9.4.3.1; 3.9.4.3.2 | Рецепти інших МІС у ПЛ незалежно від локального списку | External MR/MRR/mixed-patient/signed-original-request tests; реалізовано |
| RX-05 | 3.9.3.10.2–3.9.3.10.2.3 | Вибір МНН-компонента, перерахунок у одиниці препарату та інших компонентів | `PrescriptionRulesTest`; реалізовано |
| RX-06 | 3.9.3.7.3–3.9.3.7.3.2.1; 3.9.3.8 | Всі упаковки/кратності та container doses у двох формах | Package-union/max/container tests; реалізовано |
| RX-07 | 3.9.3.7.2; 3.9.3.11.1; 3.9.3.11.3 | Максимальна кількість/добова доза; підтвердження перевищення рекомендованої дози з (!) | Maximum/recommended/confirmation tests; реалізовано |
| RX-08 | 3.9.3.7.1 | Кількість покриває весь включний курс після перерахунку одиниць | Insufficient/sufficient course tests; реалізовано |
| RX-09 | 3.9.3.5 | end = start + duration − 1; максимум програми; повторна серверна перевірка | Inclusive dates/program-period tests; реалізовано |
| RX-10 | 3.9.3.12; 3.9.3.12.1; 3.9.3.15.1.1 | Підтвердження телефона й native notification_disabled в обох формах | Program list/detail/null/notification tests; реалізовано |
| RX-11 | 3.9.3.17.1 | Національний каталог та validated prescription-specific URL за офіційним endpoint | Exact endpoint/wrong-ID tests; доступність PREPROD endpoint потребує перевірки |
| RX-12 | 3.9.4.1.5–3.9.4.1.5.2 | Усі відпуски з датою і статусом на кожній сторінці | Two-page dispensing rendering; реалізовано |
| RX-13 | 3.9.4.1.7 | Перед повторним SMS eligibility, OTP, налаштування програми та одноразове підтвердження | Missing-confirmation/disabled/denied/validated-PATCH tests; реалізовано |
| RX-14 | 3.9.1.1.2.1 | Standalone: власна завершена взаємодія поточного дня та допустимий чинний працівник | Old-day/foreign-author/allowed tests; реалізовано |

## C01 та робочі місця

C01 реалізовано за буквальним текстом 3.10.4.5.1: scheduled, in_progress **і completed** призначення блокують скасування ПЛ. Раніше заявлене менш суворе API-правило не підтверджене перевіреним першоджерелом: operation-0171 описує підписаний вміст і посилається на модель статусів, але не встановлює такого дозволу. Твердження про нормативний конфлікт знято; guard ТВ39 збережено.

| Перевірка | Автоматизовано / що ще підтвердити |
| --- | --- |
| U01: DOCTOR/SPECIALIST/ASSISTANT/MED_COORDINATOR/MEDADMIN | Чинний автор, scopes, patient/LE context, відсутність ефектів при denial, verified/unexpired approvals; додаткова п’ятирольова матриця та спільний employee resolver завершуються. Повний зовнішній алгоритм верифікації 1.1.5 не підтверджується цим звітом. |
| U02: configuration/catalogue limits | Повна валідація і атомарне оновлення каталогів; native medical_program_settings/settings; EMAL та referral runtime values потребують підтвердження відповідним середовищем. |
| U03: OTP/OFFLINE fallback МВ | OTP/THIRD_PERSON, повідомлення без SMS та друк інформаційної довідки перевірено автоматизовано. OFFLINE у ТВ про народження та native GET union мають незакриту контрактну розбіжність; undocumented відповідь не приймається. Реальні метод/SMS/довідка окремо. |
| U04: continuation/refinement/merge/ЕРЛН МВ | Native APPENDS/REPLACES, остання ланка, точний identified/preperson pair, pending/result context і retry перевірено автоматизовано; реальний підпис, зовнішній merge та ЕРЛН окремо. |
| U05: автор ЕН читає ЕМЗ погашення | Read-only modal за completedWith; scopes/patient/type/issuer до API, full resource до UI, без імпорту чужого ЕМЗ. Workflow тести пройшли. |
| U06: госпіталізація/переведення | AMB refusal/new episode/service_delivery_location; Person/Preperson; discharge/transfer categories. Workflow тести пройшли. |
| U07: середній персонал | 21 summary action, primary_source фактичних форм, author-only cancellation, immunization exception; 35 tests / 94 assertions пройшли. Повна вакцинація/MedData за межами шести модулів. |
| U08: feedback, модалі, друк, pending | Actual Livewire errors/consumer, modal preservation, durable pending/reopen, official printout paths та sandbox tests. Browser acceptance й реальні КЕП/SMS ще не підтверджено. |

## Зафіксовані перевірки

| Прогін | Результат |
| --- | --- |
| Інтегровані CarePlan Feature/Unit/Livewire та patient registry | 518 tests / 2773 assertions, 0 failures/errors |
| Направлення, encounter pages/state та mapper/lifecycle/print | 357 / 2057, 0 failures/errors |
| RX + Episode + Shared API integration | 197 / 870, 0 failures/errors |
| Native Shared API contracts | 203 / 458, 0 failures/errors |
| ПМД/СМД ASSISTANT summary і primary-source forms | 35 / 94, 0 failures/errors |
| Додатковий CarePlan device catalogue boundary | 7 / 30, 0 failures/errors |
| Approval і device catalogue: повні response boundaries | 83 / 597, 0 failures/errors |
| Чинні працівники, п’ять ролей ПМД/СМД та DeviceRequest boundaries | 234 / 1144, 0 failures/errors |
| Строки працевлаштування виконавців направлень | 78 / 456, 0 failures/errors |
| CarePlan після нового main і перевірки чинних ролей | 535 / 2578, 6 errors, 26 failures, 11 risky; виправлення тривають |
| Медичні висновки до передачі окремому чату | 200 / 688, 0 failures/errors; подальші EMAL/U04 доповнення ще перевіряються |
| Docker frontend build | Пройшов; 224 modules, 4 попередження щодо CSS asset paths |
| Скасування взаємодії | 44 focused tests пройшли |
| Спільний checkpoint fd34d575: registration/submission boundaries та рольова матриця | 159 / 433, 0 failures/errors |
| Спільний followup e07fc925: BA03/07, точний актор BA08 та original patient/preperson sync | 177 / 507, 0 failures/errors; 16 падінь ширшої forms suite відтворюються і з попереднім helper, їх виправляють власники модулів |
| Перший CI PR #1217 | Frontend пройшов; domain job зупинився на apt DNS до тестів. Встановлений PostgreSQL запускається перед fallback apt; п’ять ізольованих mock сценаріїв CI-скрипта пройшли |
| CI `4bea6c96` | PostgreSQL/install/update та frontend пройшли; PHPUnit 2564 / 11102, 56 errors / 79 failures; браузерні кроки не виконувалися після PHPUnit failure |
| CP provider після інтеграції `12781f70` у `5f5b0680` | 84 / 347, 0 errors/failures; збережено strict verified/future-expiry approval boundaries |
| Три точки входу й ролі на точному `5f5b0680` | 95 / 1021, 0 errors/failures/skipped; 15 дозволених UI контекстів та 5 policy-denied ПМД/Preperson |
| Sidebar після точкової правки | 8 / 48, 0 errors/failures; сформований Blade HTML |
| Episode fixture followup `11be755a` | 10 / 96, 0 errors/failures; fixture підміняє актуальний syncReferencedRecords і не приховує сторонні винятки |
| MV checkpoint `efaafa8c` | 268 / 1087, 0 errors/failures; передано для інтеграції, 75 required configuration keys, live acceptance окремо |
| RX owner `a203bf04`, інтегрований як `8f13b2bc` | 215 / 984 та 4 / 12 route checks, 0 errors/failures в owner БД; зведена регресія окремо |
| Device owner `e25aee03`, інтегрований як `7714f184` | 180 / 822, 0 errors/failures в owner БД; збережено assertSuccessful перед підтвердженням підписання |
| RX / Device / encounter pages на `7714f184` | 451 / 2246, 3 errors / 23 failures; 12 випадків RX/Device і 14 направлень передано власникам для виправлення інтеграції |
| Незалежний Shared API audit на `7714f184` | 160 / 359, 0 errors/failures; окремі діагностичні тести підтвердили дві контрактні помилки направлень і відсутність видимої відмови specimen checkStatus, які виправляє R |
| Зведені Shared / role / identifier / cancellation suites на `7714f184` | 449 / 1472, 0 errors/failures; одна наявна deprecation |
| Додаткова перевірка фактичної відмови RX на `7714f184` | 1 / 8, 0 errors/failures: після усунення успадкованого рольового дозволу drawer закритий, повідомлення видиме, HTTP/створення записів відсутні. Початкова фікстура з revokePermissionTo залишала право через SPECIALIST |
| Покриття актуальних тестових файлів у CI | Усі 120 змінених Test.php входять до 83 явних domain paths; це перевірка складу запуску, не доказ проходження |
| CP owner `a671dd3c`, інтегровані власні followups | 554 / 2649 та окремі 29 / 290, 0 errors/failures; 583 унікальні cases в owner середовищі, остаточний root CP прогін залежить від R consumer |
| MV owner `0eff203c` зі strict Shared overlay | 275 / 1128, 0 errors/failures; 77 configuration keys, без production defaults |
| MV / Shared contracts / старий patient registry на `8db672c5` | 499 / 1655, 0 errors, 2 failures лише у `PatientRegistryUatTest`; повна native fixture та видима denial assertion передані MV |
| RX / Device на інтегрованому `8db672c5` | 405 / 1788, 0 errors/failures/skipped; усі 12 попередніх RX/Device несумісностей усунено. R-owned encounter pages перевіряються окремо після його final delta |
| Зведений domain/frontend CI остаточного SHA | Нові запити й перезапуски призупинено за вказівкою користувача; результат остаточного SHA не перевірявся |

Прогони перекриваються; їхні кількості не підсумовуються. Наявні PHP/PHPUnit deprecations зазначаються окремо від failures. Повні локальні JUnit/log докази збережені в `.local/tv39-remediation-2026-11-11/`.

Тести працюють в ізольованих PostgreSQL БД. Центральний прогін використовує окрему БД `testing` для suites із буквальним захисним guard; робочу `mis_dev` не мігрували і не очищали. Пізніший referral handoff виявив зовнішні GET із відповіддю 401 на окремих eHealth client instances попри глобальний `Http::preventStrayRequests`. Ізоляцію цих клієнтів ще потрібно завершити; попереднє твердження про блокування всіх випадкових HTTP-запитів було надто широким. Збережені докази не показують успішних віддалених змін.

## Нотатки бізнес-аналітика та дизайнера

| ID | Критерій приймання | Відповідальний / стан |
| --- | --- | --- |
| BA01 | Період послуги СМД; хвилини реабілітаційного заняття для відповідного ПЛ на основі взаємодії лікаря-реабілітолога | Код інтегровано; повна CP owner регресія пройшла, root consumer/regression acceptance завершується |
| BA02 | Ліміт для ПЛ/послуги: 5 + унікальні оформлені направлення − усі створені призначення; кожне направлення додає можливість, також із того самого призначення. Cancellation/completion призначення її не повертають; draft/pending і повторний callback UUID її не додають | Код інтегровано; native CP owner cases пройшли, root final R consumer acceptance завершується |
| BA03 | Усі пов’язані направлення, рецепти, е-запити, ПЛ і МВ на взаємодії з перевіркою доступу | Спільний екран у e07fc925; автоматизовано, зведена регресія ще потрібна |
| BA04 | Чотири дії для точного номера направлення; «Перегляд» веде до правильного джерела документа | Направлення; у роботі |
| BA05 | Відповідна дія е-рецепта перевіряє відхилення заявки/рецепта за підтвердженим статусом | RX denial/confirmed-status/feedback cases пройшли у root `8db672c5` |
| BA06 | Одна сторінка е-рецептів картки пацієнта з підтабами заявок і виданих рецептів | Код і patient navigation/detail cases пройшли у root `8db672c5`; реальний UI UAT окремо |
| BA07 | Доступ до всіх п’яти типів клінічних документів у картці та наявній навігації; бокове меню має часткове покриття | Спільний екран і підвкладки RX інтегровано; повторне приймання трьох точок входу триває |
| BA08 | Рецепт, направлення та е-запит із взаємодії в межах наявного ПЛ: пошук, чинний/запитаний approval, залишки й одиниці | ПЛ, направлення, рецепти, е-запити; у роботі |
| BA09 | Показ підтвердженого виробника медичного виробу у призначенні | Native manufacturer name/country, без підміни учасником програми; root `8db672c5` manufacturer/UI/boundary cases пройшли |

Ці пункти додані до umbrella issue #1201. Для кожного потрібна прив’язка до коду і позитивної/негативної перевірки; вони не зараховуються виконаними через наявність доручення.

Уточнення користувача від 11 листопада: документи вже відображаються у взаємодії, на картці пацієнта й частково в боковому меню. Наявну реалізацію враховано в [матриці трьох точок входу](UI-SURFACES-TV39.md). Приймання перевіряє статус, доступ, дії та справжнє джерело документа на кожній наявній поверхні. Відсутність окремого меню для кожного з п’яти типів сама по собі не є прогалиною ТВ39.

## Умови остаточного приймання

Потрібні правильні install **або** update migrations відповідно до типу встановлення. Для направлень — дві пари `130000_add_preperson_to_service_requests` та `160000_complete_service_request_execution_storage`; для скасування — diagnostic report storage; для згод — nullable asynchronous approval fields. Розгортання й міграція робочого середовища не виконувались.

Необхідні актуальні значення `ASSISTANT_SERVICE_REQUEST_ALLOWED_CATEGORIES`, `SERVICE_REQUEST_REUSE_AFTER_MINUTES` та EMAL configuration відповідного середовища. Відсутність значення блокує недозволену операцію; це не замінює налаштування середовища.

Prescription-specific price catalogue endpoint наявний в офіційному demo archive й відсутній у використаному PREPROD archive. Публічний каталог доступний у UI; успішне отримання специфічного URL від цільової ЕСОЗ ще має бути підтверджене.

Для завершення живого приймання потрібен КЕП користувача, контрольовані SMS та віддалені МВН/МВТН/ЕР/ЕН/е-запит outcomes. Автоматичні перевірки не засвідчують цих зовнішніх результатів. Цей звіт буде оновлено остаточним SHA, umbrella PR і фактичними локальними результатами. Зведений CI можна перевірити після окремого відновлення запитів користувачем.

## Останній стан інтеграції та публікація чернеток

11 листопада 2026 року користувач зупинив усі нові локальні й CI-тести, опитування та перезапуски CI. Пізніше окремо дозволив закомітити й запушити підготовлені зміни та оновити описи PR українською, зберігши всі PR у draft. Публікація не означає відновлення тестування або готовність до рев’ю. Останній коміт кожної опублікованої PR-гілки містить `[skip ci]`; за документованим механізмом GitHub це пропускає workflows на `push` і `pull_request`. Draft додатково виключається наявним Azure review gate. Нових CI-запитів для перевірки цього стану не виконували.

| Збережений прогін | Фактичний результат і межі |
| --- | --- |
| Root CP/R на `a22c42bf` | 1170 тестів / 6525 assertions, 8 errors і 31 failures: 4 випадки CP та 35 направлень/взаємодій. Це тестові випадки, а не 39 доведених production-прогалин. |
| Root actor/init/registry на `36ce96b0` | 72 / 434, 1 failure CP OTP initialization; інші 71 пройшли. Пізніша fixture додає обов’язкову native provenance, але повторний прогін зупинений. |
| RX/Device owner після прив’язки актора | 318 / 1593 без errors/failures; фактичні child components ПЛ 2 / 29 пройшли. Остаточне спільне дерево не прийняте. |
| Referral owner до останніх guards | 554 / 3562 без errors/failures; browser 14/14 на власному дереві. На strict root baseline 130 / 807 є 7 errors і 28 failures; scopes/native fixtures і contract expectations залишаються в роботі. |
| MV owner, optional-author/atomic enrichment | 298 / 1284 та PatientRegistry 5 / 43 пройшли у власника до зупинки. Інтегрований root після цього follow-up не перевірявся. |

Підготовлені наступні вузькі виправлення: повторне читання направлення та policy після КЕП у чотирьох recall/cancel delegates; handling валідного empty prequalify без програми; перевірка власної policy зареєстрованого направлення в CP signing wrapper; native OTP/role fixtures; atomic enrichment медичних висновків, у search summary яких відсутній author. Неперевірені після зупинки виправлення не зараховуються прийнятими.

Незавершені роботи: остаточна спільна регресія, решта CP/R fixtures і client isolation, приймання трьох UI поверхонь на фінальному SHA, цільові runtime configuration values, узгодження OFFLINE МВН, живі КЕП/SMS/ЕСОЗ та міграції робочого середовища. До main нічого не зливалось; робоче середовище не розгорталось.
