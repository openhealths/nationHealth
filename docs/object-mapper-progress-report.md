# Звіт про рефактор ObjectMapper — 06.10.2026

Міграцію всіх 17 старих медичних array-маперів завершено у [draft PR #898](https://github.com/openhealths/nationHealth/pull/898), [issue #841](https://github.com/openhealths/nationHealth/issues/841). `app/Services/MedicalEvents` більше не містить PHP-файлів. База — main `1cf8b92e` зі змердженими #792/#907. До ready залишаються інтеграційні перевірки та окремі попередні обмеження, описані нижче.

## Відповідність патерну тім ліда

DTO розташовані в `app/Dto/<Resource>`: Model для локального запису, Ehealth для API та Form для фактичної hydration. Один target підтримує кілька source classes, коли потрібні різні правила імпорту. Валідовані Livewire Form/preloaded Model передаються прямо; flat encounter rows використовують FormCollection. MapCollection обробляє вкладені списки об'єктів, чисті transforms лишаються поряд із DTO або в спільному mapping каталозі.

Workflow читається у Livewire і protected concerns; HTTP/job/verdict — наявні `app/Classes/eHealth/Api`, enums — `app/Enums`, SQL/Identifier/transaction — Repository. DTO не виконує SQL/HTTP/Auth/session lookup та не створює UUID/now. Окремих Actions/Managers чи нового сервісного шару немає.

Серіалізація використовує спільний EhealthMapping із #907. Medical normalization зберігає 0/false/[], literal keys та точні signing bytes; попередня Division поведінка не змінена. ServiceRequestPayloads, DeviceRequestPayloads і MedicationRequestPayloads видалені: production callers напряму викликають ObjectMapper. Малий Tests/Support harness потрібен лише для збереження попередніх поведінкових assertions і використовує реальні DTO/concerns.

## Що завершено

- CarePlan: Form→Model/Ehealth, remote→Model, Model→Form, legacy model-source signPlan. Activity draft/sync/create/edit, raw cancel та unsigned complete; payload builders і lifecycle/gate/validation/program guards видалені.
- ServiceRequest/DeviceRequest: create/prequalify, draft/sign/sync/print/SMS, full import/partial updates, take/qualify/complete/cancel usage. Успішний signed create зберігається до best-effort GET; ownership і Identifier links збережені.
- eRx: структурований dosage/create/prequalify, standalone string dosage, raw-first sign/reject, fallback signing, active UUID, block/unblock, print/notifications і partial sync. Lifecycle/static wrapper видалені.
- Approvals/OTP: queue/poll, scoped access, inpatient без OTP, read access, throttle та provisional UUID replacement. Pharmacy dispense: DTO, qualify/raw sign/process, employee lookup у Repository.
- PaperReferral, DetectedIssue, DeviceAssociation, Device, DeviceDispense, Specimen і Procedure: усі legacy callers переведено на DTO; payload/hydration, вкладені списки та фактичні action/cancellation workflows збережені.
- Condition, Immunization, ClinicalImpression, Observation, DiagnosticReport, Episode і Encounter: останні сім маперів замінено Ehealth/Form DTO. Episode прямо приймає свої Livewire форми для create/update/cancel/close. DiagnosticReport і Encounter cancellation зберігають raw fields та попередню семантику вибіркового скасування.
- EncounterPackageBuilder/Loader, Fhir/FhirResource та FhirMapperContract видалені. BuildsEncounterPackage/LoadsEncounterPackage містять protected workflow/context preparation, DTO — mapping. Чинний camelCase Repository input має явну boundary conversion; це не дублікат array-маперів.

Загалом видалено 16 попередніх lifecycle/guard/helper класів, усі 17 legacy-маперів, два package класи, два FHIR helpers та їхні невикористовувані інтерфейси/wrappers. `SignatureService`, `DictionaryService` та немедичні Services поза цією областю.

## Перевірки останньої хвилі

Procedure має 18 нових тестів: незалежні old JSON/hydration/cancellation contracts, actual callers та PostgreSQL для Person/Preperson × date_time/period. Останні сім ресурсів додають 79 mapping tests і 10 PostgreSQL round-trip cases. Baseline отримано зі старих маперів до видалення, а не з нового DTO. Перевіряються sparse lists, null/zero/false, date/time/DST, aliases, raw unknown fields, cancellation sections і no SQL/HTTP під час mapping.

SQL round-trip виявив стару втрату Observation.specimen: `store()` тепер зберігає Identifier, getter завантажує relation. Тест перевіряє повторну hydration, очищення reference та відсутність дубліката. Обмеження дробової Immunization dose не приховано; воно залишається окремим schema питанням.

Медична регресія: **780 тестів / 4303 assertions**, без failures/errors/skipped/risky tests; одне наявне PDO deprecation. Перевірено mapping і точні signing bytes, API/job, Repository/Identifier links, PostgreSQL round-trip, care plan/activity, referrals, eRx/device, registry, approvals і pharmacy dispense, standalone Encounter/Specimen/Procedure callers. Додатковий фінальний прогін direct callers/cancellation/SQL: **63 тести / 359 assertions**, без failures/errors, з тим самим PDO deprecation. Pint перевіряє 107 PHP-файлів останнього інкременту; `git diff --check` проходить.

Використано наявні isolated mapper841 PHP 8.5.3/PostgreSQL контейнери. Нових контейнерів не створено, робочі ohealth/employee середовища й дані не змінено. Нових application migrations та змін Composer у цьому інкременті немає. У disposable testing DB застосовано вже наявну DiagnosticReport migration `2026_03_31_124801`, щоб узгодити тестову схему з поточною базою main.

## Що ще потрібно

1. Реальний КЕП/eHealth UAT для create/sign/cancel/sync та HTTP authorization suite з Vite assets. Тестова заміна Cipher перевіряє workflow, але не замінює інтеграційний підпис.
2. Перевірка конкурентних issuance/sign операцій. Область наявної quantity-транзакції збережено; атомарність усього сценарію не заявляється.
3. Окреме виправлення схеми Immunization: форма дозволяє дробову дозу, а чинна колонка `value` — integer. DTO зберігає 1.5 без округлення (golden test), але така доза поки не записується у чинну SQL-схему. Це попереднє обмеження, не виправлене цим рефактором; PostgreSQL round-trip використовує допустимі integer/ML.
4. Окреме виправлення Division baseline failures і перевірка актуальності main перед ready. Повний Division feature suite має 91 тест / 401 assertions, 5 errors, 1 failure і 4 risky tests; ті самі збої відтворено на незміненому main `1cf8b92e`. Application-wide green не заявляється.

Composition окремого mapper/Livewire workflow у базовому main `1cf8b92e` не має; наявні API paths не змінені. Новий Composition сценарій не реалізовано і не додається штучний DTO без caller.

PR лишається draft, issue відкритою; тестове mapper841 середовище збережено для UAT і рев'ю. [План](ehealth-object-mapper-plan.md), [implementation notes](object-mapper-refactor.md).
