# Care Plan FHIR migration — module 3.10

Tracking: openhealths/nationHealth#997. Baseline: main `78e53572ed3e4068b9745c3a5a4abd5dacbd3f71`.

## Boundaries

This is a sequential backend migration of Care Plan and Care Plan Activity. The independent DeviceRequest flow created from an Encounter without a Care Plan belongs to a separate change. Referral and prescription payloads are not rewritten here. Blade, JavaScript and visual changes are specified separately; server-side Livewire adapters may change without changing their public fields/actions.

The target is the project's FHIR-shaped model, not generic HL7 JSON. ESOZ resource names, cardinalities, action envelopes and signature contracts take precedence on the wire. The existing FhirMapperContract and Fhir facade remain the extension points. No new dependencies or atomic service layer are needed.

## Internal contract

- Resource `id` is an allocated UUID, distinct from the SQL primary key. Mapping never allocates a replacement identity. A draft UUID is not proof of remote registration.
- Field names are camelCase internally; physical SQL and ESOZ JSON keys retain their contracts.
- Reference is `{identifier: {type: {coding: [...]}, value: uuid}}`. Do not discard the resource type or infer that an untyped reference denotes a Condition.
- CodeableConcept retains its full coding list and text. Goals are concepts, not identifier references.
- Activity author, reasonCode, reasonReference, goal and outcomes retain their collection cardinality.
- Activity detail contains kind/status/product/quantity/schedule; outcomeReference and outcomeCodeableConcept are resource-level fields.
- Quantity retains value/system/code/unit/comparator, including zero when supplied. Validity and positivity are separate validation concerns.
- ISO dateTime values retain their instant and available precision. A form round trip must not reset time to midnight or move it by a safety interval.
- Form conversion accepts the original resource in context. Unrepresented fields must survive; explicit null and empty collections represent an intentional clearing operation.
- A full resource import and a summary metadata update are distinct operations. Omitted summary fields cannot erase a full resource.

## Review blocks

1. Pure mappers, facade, kind enum and synthetic fixtures. No production caller switches yet.
2. Repositories, relation completeness, precision, preflight/backfill and schema migration.
3. API serialization/normalization and sync orchestration outside database transactions.
4. Server-side Livewire integration, removal of obsolete paths and actionable UI specification.

The child PRs form a review stack in the personal fork: mapping targets the stable design branch, then persistence, integration and workflows target the preceding block. The complete umbrella gathers the same commits and targets company main. Child blocks are released together, not independently to production.

## Sources

Checked 2026-10-19. The source register captured on 2026-10-17 records these PROD page versions (updated 2026-10-15); API examples alone are not a substitute for semantic method rules.

- [Create Care Plan, API-007-005-0001](https://e-health-ua.atlassian.net/wiki/spaces/ESOZ/pages/17571905622), page version 60.
- [Create Care Plan Activity, API-007-006-0002](https://e-health-ua.atlassian.net/wiki/spaces/ESOZ/pages/17571643554), page version 53.
- [Get Care Plan Activity, API-007-006-0003](https://e-health-ua.atlassian.net/wiki/spaces/ESOZ/pages/17571709053), page version 37.
- [Cancel Care Plan Activity, API-007-006-0005](https://e-health-ua.atlassian.net/wiki/spaces/ESOZ/pages/17570759126), page version 35.
- [Complete Care Plan Activity, API-007-006-0006](https://e-health-ua.atlassian.net/wiki/spaces/ESOZ/pages/17571905639), page version 34.

Fixtures under tests/Fixtures/CarePlan contain generated identifiers and synthetic dictionary codes. They test structure and lossless conversion; they are not valid clinical documents for submission or substitutes for ESOZ UAT.

## Release gate

Do not migrate the development/production database during implementation. Rehearse installation and upgrade in a disposable environment, then run preflight, backfill, repeat-run and comparison checks. Report ambiguous ownership, untyped references, duplicate identities and irrecoverable historical precision. Do not guess missing clinical values.

One deployment window: backup, suspend module writes/jobs, preflight, schema migration, repository-backed backfill, reconciliation, deploy compatible callers, smoke test, resume. A required UI change identified by the specification blocks cutover until implemented. Old columns may remain for controlled recovery but must not become a second active source of truth.

Tests require explicit approval under repository database safety rules. Use DatabaseTransactions; never add RefreshDatabase or LazilyRefreshDatabase. Real KEP signing requires the user's participation.

## Implemented cutover and compatibility

The fourth block switches draft create/update/sign, plan and activity lifecycle actions, registries and details to the canonical repositories and API classes. The former repository payload builders, network synchronization and thin CarePlan lifecycle services are removed. Server-side Livewire code contains no SQL queries or persistence calls. Existing prescription/referral drawers receive canonical activity projections; their own payload migration remains in modules 3.9/3.17.

The compatibility attributes are in-memory projections only. Historical columns remain available for backup/reconciliation but are not refreshed by new writes. A raw Eloquent model is therefore insufficient for activity quantities/products or plan categories/terms/periods: consumers must use CarePlanRepository or CarePlanActivityRepository. Livewire hydration reloads the projection. Authorization applies before mutations; only local `draft` records may be edited, signed as a new resource or deleted locally. A registered plan in `new` uses its approval/lifecycle actions.

Identity is scoped by legal entity and patient, with a unique `(legal_entity_id, person_id, uuid)` key. The same remote plan may be cached for two clinics without overwriting either clinic's state. Activity identity is unique inside its parent plan. Unknown remote employees remain references with nullable local author IDs.

`context` is retained as local UI metadata and excluded from the ESOZ create whitelist. A form upload, password or KNEDP setting never enters mapping context. An unchanged primary code retains all codings and additional concepts. Date-only activity controls are converted to the selected day's bounds and restricted by the exact parent period during draft persistence; signing uses the stored instant. Unsupported timing/string schedules survive description edits without creating an alternative period.

## Operator procedure

1. Review the entire module umbrella and its four child blocks. Complete the manual UI/ESOZ acceptance items below before scheduling production cutover.
2. Back up the database and establish the restore procedure. Pause module writes and ordinary synchronization batches. Keep the former application release available with its matching database backup.
3. Run `sail artisan care-plans:backfill-fhir --legal-entity=<localId>` separately for each clinic. The default is read-only. Preserve the JSON report outside clinical application logs. Resolve duplicate identities and ambiguous references before applying the unique indexes. Do not invent clinical values to clear the report.
4. Apply the project's schema update entrypoint, including `2026_10_19_081226_complete_care_plan_fhir_storage.php`. The update wrapper uses the same migration as installation. Precision changes affect shared Period/Quantity primitives; rehearse with a restored copy first.
5. Import every `needsRemoteSync` registered plan through the existing CarePlanFullSync flow, with its patient, clinic and authorized token explicitly set. Fetch complete plan details and every activity page. A summary alone is insufficient. Resolve denied or missing remote resources before continuing.
6. Re-run preflight. When `errors` and `needsRemoteSync` are empty, run `sail artisan care-plans:backfill-fhir --legal-entity=<localId> --write`. Only locally recoverable drafts are converted. Their allocated UUID and typed Encounter link are retained on repeat runs.
7. Re-run without `--write`; expect zero pending conversions, no ambiguity and no unconverted rows. Compare canonical resources by identity: full concepts/references, authors/contributors, product variants, quantities including zero, precise UTC periods, schedule variants, reason/goal/outcome lists and remote remainder/type. Historical lost precision cannot be reconstructed from rounded columns.
8. Smoke-test read, draft save/reopen, unsigned completion and exact-snapshot cancellation. Resume module writes/jobs only after reconciliation and the required UI gates pass. Monitor failed jobs and repeated-sync behavior.

Rollback is a compatible application fix or restoration of the matching database backup. The migration intentionally refuses a destructive numeric/time narrowing in `down()`. Running old callers against new writes would expose stale historical columns and is not a rollback procedure.

## Validation and outstanding acceptance

Automated checks cover pure mapper round trips, normalized PostgreSQL persistence, the update wrapper, idempotent backfill, tenant/parent identity, exact periods, decimal scale rejection, empty collections, multiple codings/authors/goals, unsupported schedule preservation, endpoint create/complete/cancel bodies, pagination/failure handling, job context and server-side lifecycle/approval regressions. HTTP fixtures and signing mocks contain synthetic data; they do not validate a real certificate, crypto provider or ESOZ clinical dictionary acceptance.

Before merge/cutover: exercise real user-assisted KEP creation/cancellation in the ESOZ test environment; approval authorization and OTP/inpatient branches; all three activity kinds, including service groups and device classification; cross-clinic external authors; documented program-specific validation and remaining-quantity behavior. Complete deferred UI work where the current templates cannot safely expose the stored value. These gates do not authorize deployment or claim 100% certification of module 3.10.

### Executed checks, 2026-10-19

226 distinct tests, 894 assertions, no failures/errors, on isolated Sail PHP 8.5.3 and PostgreSQL 18. The database and all fixtures were disposable; no real ESOZ token, KEP or working database was used. Laravel tests emit the existing PHP 8.5 `PDO::MYSQL_ATTR_SSL_CA` deprecation from `config/database.php:64`; this unrelated configuration was not changed.

- 166 module/approval/Livewire/linked-document regression cases, 696 assertions, against the complete install schema.
- 50 mapper/repository/API/job/lifecycle contract cases, 178 assertions. Repository contracts create a uniquely named private PostgreSQL schema inside DatabaseTransactions, including the real update migration wrapper. Rollback removes only that newly created schema. The ordinary CI tables remain untouched; the connection must use PostgreSQL database `testing`.
- 10 additional dispatch, unsigned-completion, status-enum, kind-resolution and repository-resolution cases, 20 assertions.
- Complete install migrations rehearsed with API dictionary seeding disabled in the disposable environment. Backfill preflight/write/repeat, unknown authors, cross-clinic identity, precise periods and unsupported schedules tested. Read-only preflight also tested before the new coding metadata columns exist.
- Laravel Pint applied; `git diff --check` passes. No changes under `resources/views` or `resources/js`; no new dependency or authentication/OAuth scope changes.

The automated result covers these backend contracts and regression cases. The manual acceptance list above remains open.

### Combined prescription compatibility, 2026-10-20

The CarePlan document synchronizer preserves MRR and MR as separate identities. A matched prescription marks its originating MRR `signed`, records `active_id` and updates an independent MR cache. A stale `NEW` list result cannot downgrade the signed request. Request-number matching requires one unlinked candidate; an ambiguous number is not evidence of identity. Supplied patient identifiers must match the local owner, and repeat synchronization must not create references or prescriptions again.

An MR created from a partial list remains noncanonical. Its known originating MRR may supply the local activity basis and prescribed quantity for display and counting, using an independently owned Identifier. Complete remote details must be imported before clinical use. The MR's current state controls the quantity once it is linked; its originating signed MRR no longer adds the same quantity. `EXPIRED` and `ENTERED_IN_ERROR` therefore release the corresponding reservation without changing the MRR to an MR status.

Activity storage also retains the parent Reference's original type codings and display metadata. Its UUID is enforced through the parent relationship; serialization does not manufacture empty concept text.

The combined rehearsal includes umbrellas #998, #1006 and #1011 on a private checkout. Both migration orders retain existing primitive rows and decimal(24,10). Automated synthetic coverage supplements the manual release gates above; it does not close them.
