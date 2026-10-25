# Актуалізація Symfony ObjectMapper — #841

Оновлено 25.10.2026. Це план міграції поверх актуальних медичних контрактів, включно зі змінами з аудиту ЕСОЗ. Він замінює старе припущення, що достатньо перенести flat array-мапери й видалити їхні класи.

## Бази та залежності

- Поточна база реалізації: company main `b43c425ecc53738460e8e3c27fcef52952f6decf`.
- Основна гілка рефактору: `i841_object_mapper_service_request`; основний draft — company [#898](https://github.com/openhealths/nationHealth/pull/898), head `Mefizz/ohealth`. Окремі Composition/PaperReferral та CarePlan інкременти зведено в цей PR.
- Окремий перевірений snapshot очікуваного FHIR-стеку: `i1008_clinical_fhir_uat` на `979e534727111629ca5d952ab8aba86627b9a74d`.
- Порядок основних delivery-залежностей із актуального аудиту: [#1022](https://github.com/openhealths/nationHealth/pull/1022) → [#998](https://github.com/openhealths/nationHealth/pull/998) → [#1006](https://github.com/openhealths/nationHealth/pull/1006) → [#1011](https://github.com/openhealths/nationHealth/pull/1011) → [#1042](https://github.com/openhealths/nationHealth/pull/1042).
- Device PR [#1016](https://github.com/openhealths/nationHealth/pull/1016), [#1030](https://github.com/openhealths/nationHealth/pull/1030), [#1031](https://github.com/openhealths/nationHealth/pull/1031) — окремі залежності. Їх не вважаємо автоматично включеними до комбінованого snapshot.

Опубліковані гілки аудиту — джерело цільових контрактів та регресій, але ще не доказ злиття чи остаточного схвалення. Перед кожним інкрементом повторно читаємо відповідний PR/аудит, фіксуємо SHA та перевіряємо його власний diff. Незакомічені UAT-правки не копіюємо як готову базу.

На прохання автора продовжуємо саме наявний draft #898. Повний попередній рефактор збережено, перебазовано на main, а перевірені Composition/PaperReferral і canonical CarePlan інкременти інтегровано до тієї самої head-гілки. Перед перебазуванням збережено окремі snapshots локального та опублікованого стану. Старі успішні прогони не є доказом сумісності консолідованої гілки. Зміни CarePlan поки включають незлиті передумови #1022/#998: після їхнього merge потрібна повторна актуалізація diff.

## Що змінюється в архітектурі

Розділяємо три різні представлення даних:

1. **Канонічний ресурс застосунку.** Повний FHIR-профіль проєкту з references, усіма codings, metadata, Quantity/Ratio, Period і Dosage. Його зберігає та відновлює Repository за правилами FHIR-стеку.
2. **Контракт конкретного eHealth API.** Native назви, cardinality, create/prequalify/sign/cancel envelopes та дозволений склад документа. Це не універсальний HL7 JSON. IL Composition, наприклад, має camelCase й bare Identifier; спільний snake_case serializer до нього не застосовується автоматично.
3. **Редаговані поля форми.** Проєкція для UI. Вона не замінює повний remote resource, не стає source для повторного підписання прийнятого документа і не повинна стирати невідредаговані дані.

Symfony ObjectMapper описує переходи між цими представленнями. Сам пакет не вирішує partial import, авторизацію, повноту FHIR-запису чи завершення async-операції.

## Патерн класів

- `app/Dto/<Resource>/Ehealth` — поля чинного API-контракту. Окремі create/prequalify/cancellation класи додаємо лише за різної структури запиту.
- `app/Dto/<Resource>/Model` — проєкція для Repository/локальної моделі; один target може мати кілька source classes через `Map`/`SourceClass`. Для простих Eloquent-проєкцій допустимий чинний `ModelContract::toModel()`. Збереження графа FHIR та relation writes лишаються в Repository.
- `app/Dto/<Resource>/Form` — лише там, де є фактичний hydration caller. Порожні Form/Write/Source DTO для симетрії не створюємо.
- Реальну валідовану Livewire Form передаємо напряму, як у Division/HealthcareService. Для plain array boundary використовуємо наявний `FormCollection`; `(object)` не перетворює автоматично вкладені масиви.
- Списки об'єктів проходять через `MapCollection`. Scalar lists і готові literal rows не обгортаємо додатковими класами без потреби.
- UUID, автор, заклад, operation time, dictionary context і preloaded relations готує caller. SQL/HTTP/Auth/session/UUID generation/clock не виконуються в DTO/transform.
- Helper transforms мають назви `map…`/`normalize…`, які не збігаються з mapped-властивостями: PropertyAccessor може сприйняти однойменний метод як accessor.
- Strict PropertyAccessor є стандартом. Відсутні поля задаємо явно в контрактних paths/conditions, а не приховуємо всі помилки через глобальний lenient accessor.

Для поточного інкременту достатньо статичних `fromForm()`/`fromSource()` з налаштованим ObjectMapper, як у main. Laravel binding/locator додається лише коли реально з'являються container-resolved transforms. Не вводимо новий generic mapper service або inheritance від final ObjectMapper.

## Власники поведінки

**Livewire та вузькі protected concerns:** validation/access/UI, вибір context, порядок sign → API → persist, loading/messages. Зберігаємо нові окремі сторінки та patient/encounter-scoped маршрути аудиту. Mapping не повертається у компоненти під іншим ім'ям.

**`app/Classes/eHealth/Api`:** HTTP, native envelopes, response validation, pagination, конкретні job/verdict contracts. GET не запускає приховано polling. Зміни shared jobs, OAuth/scopes чи auth не включаються в mapping-інкремент без окремої необхідності та оцінки впливу.

**Repository:** SQL, Identifier/FK resolution, graphs, transactions, scoped lookup і чітка семантика import. Model з HasCamelCasing читається через camelCase attributes; фізичні SQL/upsert columns і native API keys залишаються у своїй справжній формі.

**Enum:** типи, статуси й чисті правила без побічних ефектів. Нових Actions/Managers/Coordinators або lifecycle services як заміни старому шару немає.

## Інкременти

### 1. Composition create/cancel і заповнення форми — реалізовано

Один `Composition/Ehealth` приймає `CompositionForm` та `CompositionTempDisabilityForm`. Окремий `EhealthCancellation` приймає форму скасування. Event/Extension списки мапляться через MapCollection. Forms делегують DTO; старий BuildsCompositionPayload видалений.

Збережено camelCase native payload, bare Identifiers із type text, порядок полів, `event` як list, `period.end = null` для МВН, `extension = []`, calendar dates та relation fallback. Семантика пропуску unset create flags не змінена; явно передане typed extension value не видаляється через `empty()`.

Це не заміна raw signing документа, Repository чи модульного FHIR storage з #1042. Його виправлення false/codings/event/precision мають залишитися при інтеграції.

`Composition/Form` заповнює фактичну форму уточнення та продовження МВТН. Чистий `ExtensionValue` зберігає явне false та чинний пріоритет останнього extension. Продовження починається наступного дня після попереднього завершення, очищує кінець і порушення; уточнення зберігає попередні значення. Залишено окрему особу в `section.focus` та чинне перевизначення власного subject після ідентифікації пацієнта. Для обох режимів незалежно знято однакові baseline на main і snapshot аудиту.

### 2. PaperReferral — реалізовано

`PaperReferral/Ehealth` та `Form` замінюють старий array mapper. Перенесено обидва напрями у фактичних ProcedureMapper і DiagnosticReportMapper; старий PaperReferralMapper видалений.

Parent clinical contract і його наявна конвертація wire names збережені. Presence condition розрізняє відсутнє поле та явний null. Не змінюємо паперове/електронне precedence, false/zero, defaults і дату через глобальне очищення DTO.

### 3. Спільна FHIR-база — після узгодження #1022

Мігрувати лише чисті перетворення. Збереження Coding/Identifier/Period уже має власника в FHIR-стеку; не створюємо другий Repository чи універсальний SDK.

Обов'язкові контракти: усі codings і metadata, reference type/display, unknown fields, explicit null/false/zero/empty lists, fractional timestamps, UTC offset і DST. Наявний global EhealthMapping не змінюємо так, щоб ламати Division/HealthcareService або їхній explicit clearing.

### 4. CarePlan та Activity — ціль #998

Мігрувати актуальні CarePlanMapper/CarePlanActivityMapper, а не старі flat formatter methods. Form → ресурс зберігає невідредаговані поля seed; Model/Form hydration читає канонічний ресурс. Врахувати contributor/supportingInfo/parent references, кілька goals, Quantity precision, Period timezone та explicit clearing за фінальним контрактом рев'ю.

Підрахунок issuance не змінюється через DTO: linked MR не додається вдруге разом із originating MRR; актуальні expiry/entered-in-error та decimal quantity зберігають значення. DTO не переносить lock або scope за межі Repository.

### 5. ServiceRequest — ціль #1006

Мігрувати native/канонічні adapters окремо. Основний clinical status, program_processing_status та сумісна локальна проєкція не взаємозамінні. Підтримати поля виконавця, зокрема hospitalization performer_type, permitted resources і точний occurrence period з актуального контракту.

Full detail, partial search і форма мають різні правила оновлення: неповна відповідь не стирає автора чи references. Use/Complete/Recall/Mark-in-error перевіряються за конкретним endpoint; старі golden expectations не виправдовують неправильне `based_on` замість погодженого completion contract чи підпис фрагмента замість належного документа.

### 6. MedicationRequest — ціль #1011

Пріоритет — зберегти реалізовані в аудиті fresh native-detail signing та recovery після прийнятого підпису. При short sign response MRR лишається confirmed/signed, MR має власний UUID; невдалий detail GET завершується повторною синхронізацією без нового КЕП, дубліката та неправдивої позначки full FHIR import.

Dosage передається структуровано: Quantity/Ratio з явними одиницями, Timing, doseAndRate, maxDosePerPeriod та інші підтримувані поля. Одиниця береться з каталогу; форма випуску, умовне «од.» чи scalar default не підміняють її. Підтримати date-only поля й decimal precision без прихованого float/int cast. Старий fallback-sign зі скорочених локальних полів не повертати.

### 7. Composition persistence та решта клінічних ресурсів

Заповнення редагованої форми вже перенесено в інкременті 1. Після стабілізації #1042 окремо оцінити чисті перетворення повного remote detail → локальний граф. Не підміняти їх UI-проєкцією `Composition/Form`: metadata, всі codings, patient/encounter context, partial refresh та precision зберігаються за контрактом Repository аудиту.

Далі — Condition, Immunization, Observation, ClinicalImpression, Procedure, DiagnosticReport, Episode, Specimen, Device/DeviceAssociation/DetectedIssue/DeviceDispense. Перед кожним ресурсом перевірити актуальні `EncounterPackage` forms, нові policies та його власні зміни в main. В Observation перенести numeric components/scores із підготовленим context, зберігаючи SQL у Repository.

### 8. DeviceRequest, dispense та approvals

Окремо врахувати #1016/#1030/#1031 та наступні зміни модулів 3.20/3.22. Не прирівнювати невідомий remaining quantity до безлімітності, не вигадувати quantity=1, не плутати DeviceRequest-request і patient signed-create endpoints.

Approval queue/poll/OTP/read/inpatient flows і pharmacy qualify/process/signing — workflow, а не DTO transforms. Помилка Qualify не стає успішним відпуском через міграцію mapper. Ці умови перевіряються та виправляються у модулі-власнику, а потім зберігаються рефактором.

### 9. Видалення старого медичного шару

Після завершення кожного вертикального сценарію видаляти лише його класи без callers, imports/bindings і динамічних викликів. Encounter package orchestration читається у компонентах/protected concerns; Fhir facade/helper/contract видаляються після останнього споживача.

Кінцева ціль — відсутність прикладних `Services/MedicalEvents` і legacy-маперів. Під час консолідації збережено поточні DeviceRequest prescribing service/mapper та CarePlan approval/gate/quantity helpers з main/аудиту: старий рефактор ще не покриває їхні нові контракти. Повернення старого DeviceRequest stub заради видалення сервісів неприпустиме. Signature/Dictionary infrastructure та інші немедичні модулі не включаємо механічно.

## Правило приймання та оновлення

1. Зафіксувати main SHA, source SHA потрібного audit PR та його review-рішення. Повторно звірити за нових push/merge, особливо після rebase/squash.
2. Спочатку зняти незалежні fixtures зі старої реалізації, звіривши потрібну поведінку з reviewed API-контрактом. Відомий дефект не перетворюється на правило лише через golden test.
3. Перенести mapping і фактичні callers. Без SQL/HTTP/генерації UUID/clock під час mapping; без прихованої зміни auth/scopes/jobs.
4. Перевірити exact payload/hydration, presence/null/false/zero, metadata, raw signing, actual callers і негативні шляхи. Для persistence-інкременту додати PostgreSQL round-trip/partial-import/idempotency та migration checks.
5. Прогнати інкремент і на поточному main, і на pinned цільовому audit snapshot. Перед overlay перевірити оригінальні змінювані файли; відмінності зливати явно. Підтвердити фактичні autoload/helper paths знімка.
6. Публікувати тільки власний інкремент. Неприйняті FHIR-передумови не копіюються вдруге у company PR під назвою mapper; для залежної роботи до їх злиття допустимий окремий fork review diff.
7. Перевірені module/DTO тести не означають готовність усього застосунку або проходження всіх ТВ. КЕП, UI/UAT, DB concurrency і клінічні дії мають власні докази.

Критерій зупинки масштабування: SQL/data lookup у Map closures, підміна повного ресурсу UI-проєкцією, один DTO для несумісних envelopes або глобальний serializer, що знищує metadata/false/null. Такий інкремент спочатку виправляється, а не копіюється на решту модулів.

Поточні результати та точні межі перевірок: [object-mapper-progress-report.md](object-mapper-progress-report.md).
