# Рефактор eHealth на Symfony ObjectMapper — #841

Оновлено 06.10.2026 після завершення міграції всіх старих медичних array-маперів. Issue [#841](https://github.com/openhealths/nationHealth/issues/841), draft PR [#898](https://github.com/openhealths/nationHealth/pull/898), гілка `Mefizz/ohealth:i841_object_mapper_service_request`. База — main `1cf8b92e`, інтегрована 05.10, включно зі змердженими #792 та #907. Початковий gate виконано; гілка #792 не змінювалася рефактором.

## Результат і межі

У `app/Services/MedicalEvents` не залишилося PHP-файлів. Усі 17 legacy-маперів, lifecycle/guard класи, EncounterPackageBuilder/Loader, Fhir/FhirResource та FhirMapperContract видалено після перенесення callers. Пошук охоплює application, tests, jobs, commands, providers і bootstrap. Test harness використовує ті самі protected concerns та DTO, а не копії production-маперів.

Scope: care plans і activities, направлення, eRx, approvals, pharmacy dispense та клінічні ресурси encounter. SignatureService, DictionaryService і Services інших модулів залишаються окремою областю. #841 не означає фізичне видалення всієї `app/Services`.

## Архітектура за патерном #907

- `app/Dto/<Resource>/Model` — явна проєкція для локального запису; кілька source classes мають власні class/property Map та SourceClass.
- `app/Dto/<Resource>/Ehealth` — wire-поля API. Окремі Create/Prequalify/Update/Cancellation/Closure DTO лише для справді різних API-контрактів.
- `app/Dto/<Resource>/Form` — фактичне заповнення форми; не створюємо порожні DTO для симетрії.
- Вкладені об'єкти мають малі DTO; списки об'єктів проходять через MapCollection. Scalar lists і готові literal dictionary rows не обгортаємо штучно.
- Валідовану Livewire Form або preloaded Model передаємо напряму, коли їхня структура відповідає source-контракту. Для flat encounter rows використовуємо наявний FormCollection; для validated remote arrays — наявні response Collections/Collection/stdClass.
- UUID, час, legal entity, writer, dictionary labels і preloaded details передає caller. Mapper не виконує SQL/HTTP/Auth/session lookup і не генерує UUID/now.
- Серіалізація через спільний EhealthMapping із #907. Protected normalization hook зберігає попередню поведінку Division, а medical DTO — zero/false/[], literal keys та порядок підписуваного JSON.
- Symfony ObjectMapper final не успадковуємо. Laravel provider зв'язує ObjectMapperInterface і PSR-11 locators; class-name callables реєструються явно, attribute-instantiated pure transforms не потребують binding.
- ServiceRequestPayloads, DeviceRequestPayloads і MedicationRequestPayloads видалено. Caller викликає `map(source, Target::class)->toArray()`; складний source готує чистий DTO factory з явним контекстом.

HTTP лишається у `app/Classes/eHealth/Api`, enums — `app/Enums`. Нового Actions/Manager/Coordinator шару немає, laravel-data не використовується. Map attributes не додаємо на Eloquent.

## Власники логіки

Livewire відповідає за validate/access/UI, вибір контексту, підпис та порядок операцій. Повторювані кроки містяться у protected concerns за операціями: draft/sign/sync/print, activity/approval workflow, BuildsEncounterPackage та LoadsEncounterPackage. Їхні methods не стають новими public Livewire actions. Спільний referral execution trait має явні аргументи та працює також для HTTP-контролера.

API відповідає за endpoint/envelope, response validation, pagination, job polling/verdict. `createSignedAndResolve`, `cancelAndResolve`, `completeAndResolve` показують очікування job явно; GET не запускає polling приховано. Api не залежить від Livewire, auth/session, SignatureService чи Eloquent persistence. Async approval queue/link polling збережено.

Repository відповідає за Identifier/local FK, relation queries, scope, SQL, транзакції та aggregate persistence. Область quantity lock не розширюємо приховано. `toRepositoryDocument()` у package concern — явна адаптація DTO snake_case до чинного camelCase Repository input, а не друге картування полів. DTO не виконує SQL для UUID→FK.

Enum описує статуси, підтримувані типи та правила без побічних ефектів. EncounterRecordType містить попередні supported cancellation sections; Conditions скасовуються разом із encounter, DeviceDispense не додається до cancellation API без зміни контракту.

## Завершені інкременти

1. Provider/package/shared transforms та незалежний outbound referral spike.
2. ServiceRequest/DeviceRequest: create/prequalify, багатоджерельний Model, full search import/partial sync, draft/sign/print/SMS, take/qualify/complete/cancel usage. Lifecycle services і request-мапери видалені.
3. eRx: структурований dosage/create/prequalify, standalone string dosage, raw-first sign/reject, fallback document, active UUID/block/unblock і partial metadata sync. Lifecycle/static wrapper/mapper видалені.
4. CarePlan Form→Model/Ehealth, remote→Model, Model→Form і legacy Model signPlan; Activity draft/sync/create/edit, raw cancel і unsigned complete. Payload builders, validation/gate/program/quantity guards видалені.
5. Approvals/OTP/async polling, pharmacy dispense; quantity scope/status lists у Repository/enum.
6. PaperReferral, DetectedIssue, DeviceAssociation, Device, DeviceDispense, Specimen і Procedure: payload/hydration та наявні action/cancellation callers.
7. Condition, Immunization, ClinicalImpression, Observation, DiagnosticReport, Episode, Encounter: DTO замість останніх семи маперів. Package workflow перенесено у Livewire concerns, facade/helpers/contract і request context adapters прибрані.

Raw remote documents зберігаємо окремо від проєкції create DTO. Composition згадувався в початковому плані як наступна хвиля; інвентар поточного main не містить окремого Composition mapper/Livewire workflow. Наявні composition API paths залишаються чинними; новий DTO без caller не додаємо. Це не твердження про реалізацію окремого нового Composition сценарію.

## Контракти, які збережено

- ObjectMapper/Serializer 8.1.8 зі змердженого #907; PHP minimum 8.4.1, перевірений runtime 8.5.3. Не оновлюємо весь Composer стек.
- JSON missing/null/[], integer/float zero, false, key order, sparse-list правила, UTC/timezone/DST. SignatureService flags: JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION. Порівнюємо байти до Cipher, не PKCS#7.
- based_on/context — Identifier FK; UUID draft/prescription/active document не підміняються локальними ID. Prequalify envelope відрізняється від signed-create; program/programs мають різні контракти.
- Partial GET не стирає author/relations/dosage через відсутнє поле. Full-import, minimal-use і sync мають різні SourceClass policies; explicit null/[] застосовуються за конкретним контрактом.
- Raw unknown clinical fields зберігаються при signing/cancellation. UUID/time генерує caller. Якщо потрібна реконструкція, дані проходять той самий package mapping із попередньо завантаженими references.
- CarePlan API зберігає validator/response contract, validated pages записуються лише після повної pagination. Legal-entity context явний.
- INVALID/failed/timeout verdict не дає успішного local persist. Signed create записується до додаткового best-effort GET. Loading очищується після помилки; eRx sync підтримує кілька рецептів.
- Ownership, patient/care-plan scope, quantity checks, SMS/OTP, resend throttling та async jobs збережені. AJAX — один localized toast, redirect — session flash.
- Legacy device request-request endpoint і signed_device_request_request не замінюються patient createSigned через схожі назви.

## Перевірки

Кожен ресурс має незалежні golden fixtures зі старого коду, отримані до заміни. Tests перевіряють exact JSON/hydration/no IO, SQL/Identifier round-trip для Person/Preperson, фактичні Livewire/API/Repository callers та failure paths. Очікування не перегенеровуються з нової реалізації. Видалення класу завершується пошуком imports, bindings і dynamic calls.

При перевірці Observation round-trip виправлено стару втрату specimen reference у `store()` і додано eager loading цього зв'язку для повторної hydration. Тест перевіряє запис, читання, очищення й відсутність дубліката.

Медична регресія: **780 тестів / 4303 assertions**, без failures/errors/skipped/risky tests; одне наявне PDO deprecation. Перевірено mapping і точні signing bytes, API/job, Repository/Identifier links, PostgreSQL round-trip, care plan/activity, referrals, eRx/device, registry, approvals і pharmacy dispense, standalone Encounter/Specimen/Procedure callers. Додатковий фінальний прогін direct callers/cancellation/SQL: **63 тести / 359 assertions**, без failures/errors, з тим самим PDO deprecation. Pint перевіряє 107 PHP-файлів останнього інкременту; `git diff --check` проходить.

Використано наявні isolated mapper841 PHP 8.5.3/PostgreSQL контейнери. Нових контейнерів не створено, робочі ohealth/employee середовища й дані не змінено. Нових application migrations та змін Composer у цьому інкременті немає. У disposable testing DB застосовано вже наявну DiagnosticReport migration `2026_03_31_124801`, щоб узгодити тестову схему з поточною базою main.

## Перед ready

1. Реальний КЕП/eHealth UAT для create/sign/cancel/sync та HTTP authorization suite з Vite assets. Тестова заміна Cipher перевіряє workflow, але не замінює інтеграційний підпис.
2. Перевірка конкурентних issuance/sign операцій. Область наявної quantity-транзакції збережено; атомарність усього сценарію не заявляється.
3. Окреме виправлення схеми Immunization: форма дозволяє дробову дозу, а чинна колонка `value` — integer. DTO зберігає 1.5 без округлення (golden test), але така доза поки не записується у чинну SQL-схему. Це попереднє обмеження, не виправлене цим рефактором; PostgreSQL round-trip використовує допустимі integer/ML.
4. Окреме виправлення Division baseline failures і перевірка актуальності main перед ready. Повний Division feature suite має 91 тест / 401 assertions, 5 errors, 1 failure і 4 risky tests; ті самі збої відтворено на незміненому main `1cf8b92e`. Application-wide green не заявляється.

Issue лишається відкритою, PR — draft. Rollout потребує перевірок вище. Відкат окремого інкременту — revert його commit. Детальний [звіт](object-mapper-progress-report.md) і [implementation notes](object-mapper-refactor.md).

## Поза #841

SignatureService/DictionaryService — погоджена інфраструктура. Employee processor/matcher, PartyVerification, MedData/VaccineLot і email services потребують окремого інвентарю callers. Не переносимо сторонній HTTP у eHealth Api, кеш у enum чи lifecycle в перейменований manager.
