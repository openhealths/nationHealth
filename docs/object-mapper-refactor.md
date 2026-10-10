# ObjectMapper implementation — #841

Draft PR: [#898](https://github.com/openhealths/nationHealth/pull/898). Issue: [#841](https://github.com/openhealths/nationHealth/issues/841).
Branch: `Mefizz/ohealth:i841_object_mapper_service_request`.
Base: upstream main `1cf8b92e`, integrated October 5 including merged #792/#907.
Updated October 6 after completing all legacy medical mapper migrations.

## Mapping boundaries

Symfony ObjectMapper/Serializer 8.1.8 and the shared EhealthMapping trait follow merged #907.
Targets live in `app/Dto/<Resource>`: Model for persistence projection, Ehealth for API contracts,
Form for real hydration callers. Class/property Map and SourceClass select different form/model/import
policies in one target. A validated Livewire Form or preloaded Model is a direct source when useful;
existing FormCollection/response Collections/stdClass adapt flat or validated array boundaries.

ObjectMapperInterface uses Laravel PSR-11 transform/condition locators. Class-name callables are bound
explicitly, while attribute-instantiated pure MapObject/SourceClass/SourceHasPath need no registration.
ObjectMapper is final and is used through composition rather than inheritance.

UUIDs, operation time, writer/legal entity, dictionary labels and reference display details come from
the authorized caller. Mapping has no SQL, HTTP, auth/session access, UUID generation or clock reads.
Small pure transformations stay on DTOs; shared FHIR/reference/concept transforms remain reusable.
MapCollection handles object lists. Distinct Create/Prequalify/Update/Cancel contracts correspond to
different wire shapes rather than introducing DTOs for every possible arrow.

Livewire owns validation/access/UI and operation order, with protected concerns for repeated steps.
API owns HTTP/envelope/validation/pagination/job/verdict. Repository owns Identifier/FK resolution,
queries, aggregate persistence and transactions. Enums describe pure statuses/type rules.
No replacement Actions/Manager/lifecycle layer is introduced.

Medical DTOs reuse EhealthMapping with a protected normalization hook, preserving Division's default
behavior. Exact signed key order, missing/null/[], zero/false, literal dictionary keys and date/time/DST
semantics are verified against independent old fixtures. Raw accepted documents are stored/signed
separately from create projections so unknown clinical fields survive.

## Completed workflows

- ServiceRequest/DeviceRequest: create/prequalify, full search import/minimal use/partial sync,
  draft/sign/print/SMS, take/qualify/complete/cancel usage. Signed create persists before enrichment GET.
- eRx: dosage/create/prequalify, standalone string dosage, raw-first sign/reject, fallback document,
  active UUID/block/unblock, print and metadata sync. Distinct request/prescription identities remain.
- CarePlan: direct Form→Model/Ehealth, remote→Model, Model→Form and legacy model signPlan.
  Activity draft/sync/create/edit, raw cancel and unsigned complete; no Repository payload builders.
- Quantity/status guards: existing SQL row lock/transaction scope in Repository, pure lists in enums,
  UI validation in protected concerns, participation HTTP/pagination in API.
- Approvals: queue/link polling, patient/care-plan scopes, read/inpatient/OTP paths, throttle and
  provisional UUID replacement. All contract pages validate before persistence.
- Pharmacy dispense: validated Form→Ehealth, qualify/raw signing/process, Repository employee lookup.
- All 17 former array mappers: ServiceRequest, DeviceRequest, MedicationRequest, PaperReferral,
  DetectedIssue, DeviceAssociation, Device, DeviceDispense, Specimen, Procedure, Condition,
  Immunization, ClinicalImpression, Observation, DiagnosticReport, Episode and Encounter.

There are no PHP files left in `app/Services/MedicalEvents`. EncounterPackageBuilder/Loader,
Fhir/FhirResource and FhirMapperContract are removed. BuildsEncounterPackage/LoadsEncounterPackage
contain protected workflow/context preparation and call the DTOs. `toRepositoryDocument()` explicitly
adapts snake_case wire projections to the existing camelCase Repository contract, preserving nested
objects; field-level mapping is not duplicated.

ServiceRequestPayloads/DeviceRequestPayloads/MedicationRequestPayloads are removed from application
code. Callers map directly; pure source factories receive context explicitly. Tests/Support harnesses
only expose those real concerns/DTOs to existing behavior assertions.

Episode accepts its actual create/cancel/close Livewire forms as well as FormCollection. DiagnosticReport
and Encounter cancellation retain full snapshots, unknown fields and strict selected-record semantics.
EncounterRecordType retains the previous supported sections; Conditions cannot be cancelled separately
and DeviceDispense is not added to an unsupported cancellation endpoint.

## Independent baselines and persistence

Expectations were captured before replacing each implementation and are not regenerated from the DTOs:

- ServiceRequest outbound `4b1f0e7`; DeviceRequest `9eb61910`; eRx `2ea796ca`.
- CarePlan form payload `17955764`; independent model/sign/hydration and activity/dispense contracts.
- PaperReferral old JSON and actual parent calls; DetectedIssue/DeviceAssociation `1084b17e`.
- Device `b6d85983`, DeviceDispense `3fefa411`, Specimen `62602e72`, Procedure `f83d1c68`.
- The final seven clinical resources: unchanged legacy implementations at `c86c92c2` before migration.

Procedure adds 18 tests including actual callers and SQL round-trips. The final seven resources add
79 mapping tests and 10 Person/Preperson PostgreSQL round-trips. Existing signing/no-IO assertions
are preserved when obsolete wrappers retire. Specimen signing tests substitute Cipher and are not
real KEP integration tests.

Observation store previously lost its specimen Identifier. It now persists that reference and eagerly
loads it for hydration; SQL tests cover loading, clearing and avoiding duplicate rows.
Immunization's existing integer dose column still rejects fractional values despite numeric form
validation. Golden JSON preserves 1.5; SQL tests use supported integer/ML. Schema remediation is separate.

## Validation

Медична регресія: **780 тестів / 4303 assertions**, без failures/errors/skipped/risky tests; одне наявне PDO deprecation. Перевірено mapping і точні signing bytes, API/job, Repository/Identifier links, PostgreSQL round-trip, care plan/activity, referrals, eRx/device, registry, approvals і pharmacy dispense, standalone Encounter/Specimen/Procedure callers. Додатковий фінальний прогін direct callers/cancellation/SQL: **63 тести / 359 assertions**, без failures/errors, з тим самим PDO deprecation. Pint перевіряє 107 PHP-файлів останнього інкременту; `git diff --check` проходить.

Використано наявні isolated mapper841 PHP 8.5.3/PostgreSQL контейнери. Нових контейнерів не створено, робочі ohealth/employee середовища й дані не змінено. Нових application migrations та змін Composer у цьому інкременті немає. У disposable testing DB застосовано вже наявну DiagnosticReport migration `2026_03_31_124801`, щоб узгодити тестову схему з поточною базою main.

## Before ready

1. Реальний КЕП/eHealth UAT для create/sign/cancel/sync та HTTP authorization suite з Vite assets. Тестова заміна Cipher перевіряє workflow, але не замінює інтеграційний підпис.
2. Перевірка конкурентних issuance/sign операцій. Область наявної quantity-транзакції збережено; атомарність усього сценарію не заявляється.
3. Окреме виправлення схеми Immunization: форма дозволяє дробову дозу, а чинна колонка `value` — integer. DTO зберігає 1.5 без округлення (golden test), але така доза поки не записується у чинну SQL-схему. Це попереднє обмеження, не виправлене цим рефактором; PostgreSQL round-trip використовує допустимі integer/ML.
4. Окреме виправлення Division baseline failures і перевірка актуальності main перед ready. Повний Division feature suite має 91 тест / 401 assertions, 5 errors, 1 failure і 4 risky tests; ті самі збої відтворено на незміненому main `1cf8b92e`. Application-wide green не заявляється.

Signature/Dictionary infrastructure and non-medical Services are outside #841. No standalone Composition
mapper/Livewire workflow exists in the base main `1cf8b92e` inventory; existing composition API paths remain unchanged.
No unused Composition DTO is introduced. Issue stays open and the PR stays draft.

Full [plan](ehealth-object-mapper-plan.md) and [progress report](object-mapper-progress-report.md).
