# Composition TV39 remediation (#1204)

This change covers MV01–MV05, Composition G01 and the Composition portions of U02–U04. BA03/BA07 receive a Composition data provider for the shared encounter view. The combined encounter UI belongs to the shared clinical change. This is an implementation and test ledger, not certification of live PREPROD or KEP acceptance.

## Source contracts

Only approved TV39 is used: NSZU order 464 of 25 August 2026, extracted in `docs/technical-requirements/edition-39.json`. Composition Swagger 2.39.2 was retrieved on 11 November 2026. The native configuration, authentication and encounter schemas come from the official PREPROD General MIS 9.15.9 / Medical Events 9.15.6 archive; source hashes are recorded in `resources/ehealth/contracts/composition-responses.json`.

The schema records narrowly documented overlays from official Create Composition and Cancel Composition rules. In particular, Swagger 2.39.2 omits `PREGNANCY` from its category enum, although TV39 3.8.2.5.4 (page 99) and Create Composition's pregnancy checks explicitly require it. The overlay adds that exact category; unknown categories remain invalid. Other overlays preserve documented `appends`, native object relations, `INFORM_WITH`, treatment violation dates, newborn open-ended periods and cancellation status.

Official source locations:

- Composition Swagger: `https://api.swaggerhub.com/apis/ehealthua/compositions/2.39.2`.
- Create Composition rules: `https://e-health-ua.atlassian.net/wiki/spaces/ESOZ/pages/17571807297`.
- Cancel Composition rules: `https://e-health-ua.atlassian.net/wiki/spaces/ESOZ/pages/17571282991`.
- Environment configuration parameters: `https://e-health-ua.atlassian.net/wiki/spaces/ESOZ/pages/20188037123`.
- Configuration parameter catalogue (disability speciality map CP-02801 and switch CP-02802): `https://e-health-ua.atlassian.net/wiki/spaces/ESOZ/pages/19554074656`.
- Historical category-to-speciality map shape, with illustrative environment values: `https://e-health-ua.atlassian.net/wiki/spaces/EH/pages/907837561/Variables`.
- Fresh identified-person eligibility rule, API-010-051-0002: `https://e-health-ua.atlassian.net/wiki/spaces/ESOZ/pages/17570725890`.

## Configuration and preflight

`EHEALTH_COMPOSITION_EMAL_CONFIG` is an explicit JSON mirror of deployment-specific legacy EMAL chart parameters. `config/compositions.php` supplies no production defaults. `CompositionConfiguration` validates the complete mirror against the 77-key schema before any value is consumed. `docs/tv39-remediation/composition-emal.example.json` and the matching test fixture are illustrative values, **not verified PREPROD settings**; operators must obtain the actual environment values. `IDENTITY_DOCUMENT_TYPES_FOREIGN` is the global MPI document-type parameter included with the Composition mirror.

Modern `GET /api/composition_configurations` returns native configuration records with coded type/category, UUID/audit fields, boolean `is_active`, JSON-string `settings` and paging. Its schema is validated before settings are decoded. It is not treated as a legacy `{name, value}` EMAL catalogue and does not automatically supply the mirror.

The distinction between required configuration and optional filters is deliberate:

- Author status/type/position, officio speciality/category mapping, custodian, subject/focus type and equality, verification and configured age bounds are mandatory preflight inputs. NEWBORN additionally requires newborn identity and mother's authentication context.
- Non-officio specialities are checked only when `EMAL_VALIDATION_AUTHOR_CATEGORIES_BY_SPECIALITY_ENABLED` is true. Newborn/disability doctor-speciality, encounter type/division/diagnosis, period, age, gender, duplicate and signing-delay filters follow their documented enable switches.
- A missing category in an explicitly optional diagnosis/document/gender restriction, or a documented null restriction, is unrestricted. The sparse disability doctor-speciality map follows the same absent-category behavior; this is an inference from its documented map shape, tested separately from mapped allowed/refused and disabled cases. A missing/invalid entire environment mirror is an unavailable configuration and blocks submission. Those cases are not interchangeable.
- `IS_FOREIGN_TREATMENT` skips the documented single-period/start-window checks; `IS_FORCE_RENEW` skips the documented case-length checks. Neither bypasses identity, policy, required payload or native response validation.

`CompositionCreateComponent` enforces the selected current employee contract and own finished encounter, configured period/category/extensions, author/custodian, separate subject and incapacitated focus, native authentication metadata and fresh native encounter diagnoses. A fresh validated `GET /api/persons/{id}/authentication_methods` response is required once per distinct identified subject/focus at preflight. API-010-051-0002 checks `status=active` and `is_active=true` before 200, so relying on that successful read is an inference from its documented business precondition. A valid empty method list attests eligibility, not an existing method, and no person status field is invented or persisted. Read errors and malformed bodies block creation before POST/storage/queue effects. Preperson status is checked from its actual local field. Care categories retain the child/patient as `subject` and select the caregiver as `section.focus`; they do not reassign the patient record. Pregnancy offers every configured initial/continuation duration.

## Native responses and asynchronous results

Composition clients validate native detail/search/job/integration/print responses before mapping, storage, UI publication or completion decisions. Delegating methods retain response validation. Creation, signing, cancellation and ERLN retry retain their asynchronous operation records; a remote DONE job is confirmed locally only after a full native document is read and its identifier, subject, author, encounter, type and expected status match the operation. Malformed responses or mismatched context leave the operation pending and retryable. A search fallback reads all offset pages and refuses duplicate page results or ambiguous matches.

The published native search summary contains identifier/status/title/type/date/encounter/episodeOfCare and may omit author and subject. Patient-wide search supplies the subject context; a focus search without subject never assigns a document to the viewed patient. Registry enrichment validates full native details in the exact patient/composition/episode/encounter context before adopting an unknown author. Known author/type still must match. If detail contains episodeOfCare, that identifier must match too. Every page and readable full detail is validated before a single storage transaction publishes the batch; a later invalid response or storage refusal leaves earlier records and relations unchanged. Search-only permission does not grant a full detail read or fabricate author metadata.

Signing reads the full native document again, preserves it for KEP, and rechecks configured author/category/participant rules and signing deadline. A field or guard failure leaves the signature modal open and displays translated feedback through session flash and the established message consumer. Post-identification refinement rechecks the exact SIGNED merge pair immediately before KEP.

Continuation rereads the previous FINAL document and retains category/subject/focus. Create Composition rules determine `appends` on the eHealth side; the selected continuation target is form-prefill context and is not submitted as a manual `appends` relation. Ordinary case continuation can overlap and use another eligible author. Configured total case length walks earlier links, detects cycles and includes the new period's start. Pregnancy continuation starts on the next day and is allowed only once; an explicitly forced new pregnancy case uses the initial duration set and does not apply continuation adjacency. Explicit `replaces` refinement reads every search page and full native details for potential later links, searching both the previous preperson and current identified person. A later FINAL link or another locally pending SIGN of a replacement blocks an earlier link. The previous record must belong to the same patient or the exact signed identified/preperson pair, checked again before KEP.

## TV39 mapping

| Requirement | Implementation / evidence |
| --- | --- |
| 3.8.1.1, 3.8.2.1 | Existing `CompositionPolicy` role/entity pair plus current linked, started author selection in `User`; policy and workflow tests include SMD specialist, PMD doctor, unavailable roles and assistant authoring/sign/cancel denial. |
| 3.8.1.5.1–3.8.1.5.2.1, 3.8.2.5.1–3.8.2.5.3 | Own encounter, required payload/context and dynamic preflight in `CompositionCreateComponent`, newborn identity in `CompositionCreate`, subject/focus care flow in `CompositionTempDisabilityCreate`. |
| 3.8.2.5.4 | `CompositionPregnancyPeriodMode` identifies the configuration key; `CompositionTempDisabilityCreate` builds choices and checks periods against the validated mirror. Existing test sources cover valid duration, next-day continuation, gap and repeated continuation. |
| 3.8.1.7, 3.8.2.9 | Fresh full native read before `SignatureService::signData`; deadline/category and visible signing-field denial tests. |
| 3.8.2.12–3.8.2.12.1 | Exact SIGNED merge pair, fresh previous FINAL document and last-link search before refinement/KEP; unsigned/foreign pair and unsynchronized later-link negative tests. |
| 3.8.2.15.1 | Existing author/FINAL cancellation policy and configured cancellation-day guard; async cancellation remains unconfirmed until expected native status is read. |
| 3.8.2.8.2, 3.8.2.11, 3.8.2.17 | Patient/preperson registry, native detail preservation and full focus-search results; external subjects are displayed without ownership reassignment. |
| BA03/BA07 Composition provider | `CompositionRepository::forEncounter()` applies patient/encounter and both read policies, eager-loads detail relations and returns only readable rows; allowed/foreign-patient/foreign-entity/read-denial tests. |

## Acceptance surfaces

Existing display is preserved and must not be counted as absent. Scope is the two Composition document types, not every clinical document or five new sidebar menus.

| Surface | Existing behavior and this change | Acceptance boundary |
| --- | --- | --- |
| Encounter | Existing `medical-reports` accordion and policy-gated “add conclusion” link remain. The new `forEncounter()` provider supplies locally synchronized source-patient records with native status/detail references. | Shared owns connecting provider rows to the combined record cards, opening the same Composition UUID and linking its encounter/episode source. These UI transitions require integrated rendered/browser evidence. Composition has no direct care-plan activity source field to fabricate. |
| Patient/preperson card | Existing Composition registry shows NEWBORN/TEMP_DISABILITY, category, validity period, title and translated PRELIMINARY/FINAL/ENTERED_IN_ERROR status. UUID-based details/print/integration/cancel/ERLN actions remain guarded. Focus search now displays all valid results without storing another patient's document under the current patient. | Person and preperson canonical registry/create routes exist. Refinement/continuation links keep the previous UUID; server guards enforce eligibility. Direct foreign-patient detail access is denied without API/write effects. Automated Livewire rendering evidence is distinct from live browser UAT. |
| Partial navigation | Existing patient layout's medical-report tab and encounter aside's medical-report anchor are retained. Navigation uses the same canonical person/preperson routes and current policy. | No standalone full Composition sidebar suite is introduced. A deep link to the same selected record/source must be checked in Shared integration; the present registry opens details through a UUID action. |

SMD (`OUTPATIENT` + `SPECIALIST`) may create both types when scopes, current employee and configuration allow it. PMD (`PRIMARY_CARE` + `DOCTOR`) may create TEMP_DISABILITY only. ASSISTANT cannot author, sign or cancel, even if those scopes are supplied; read/list follows the existing read/search policy rather than an invented blanket ban. Patient/preperson ownership is applied at registry/repository boundaries. The full role/entity and surface matrix still needs actual integrated browser acceptance.

## Open technical and acceptance items

- Native `GET /api/persons/verifications` exposes `person_status`, but its current API-010-056-0003 flow requires an employee's active declarations and cannot universally check SMD subjects/caregivers. The fresh authentication-method read above provides a documented general active-eligibility precondition when `person:read` is available; it does not expose or fabricate a status field.
- TV39 3.8.1.4.2 (birth, page 93) lists OTP/OFFLINE/THIRD_PERSON, whereas the native Get Person Authentication Methods GET union and current examples document only OTP/THIRD_PERSON. Temporary disability 3.8.2.4.2 (page 97) lists those two methods. Both 3.8.1.4.4 and 3.8.2.4.4 explicitly permit unselected `inform_with` after a no-SMS/printed-certificate warning, implemented with a translated acknowledgement. The birth OFFLINE GET discrepancy remains a verified documentation/contract mismatch. Strict validation refuses an undocumented OFFLINE-shaped body, including at fresh eligibility preflight; no union is invented or weakened. The existing authentication-management route and refresh remain available; live OFFLINE/certificate acceptance is open.
- `EMAL_FILTER_DOCTOR_SPECIALITY_DISABILITY` and its enabled switch are now mirrored and enforced. The current catalogue establishes both parameters and the transferred, no-longer-maintained Variables table establishes the map shape. Historical examples are not current PREPROD values; operators must supply the actual deployment map.
- Actual PREPROD EMAL values and provider read availability, real KEP/SMS, shared merge initiation, information-certificate acceptance and combined encounter/navigation transitions require operator/user verification. This draft must not claim complete live U02/U04 or TV39 acceptance.

Automated Composition checks use the guarded `tv39_1204` database. The separate PatientRegistry UAT uses a disposable PostgreSQL `testing` database with schema-only fixtures and an exact-host bootstrap guard. No `mis_dev` migrations, seeding or mutations and no real KEP/SMS were performed. Tests drive the worker logic explicitly; no live queue worker was started. Final counts, commit SHA and PR are recorded in the local handoff rather than guessed here.

## Component + Form refactor — 2026-11-12

The conclusion module follows the team-lead agreement recorded on November 12, 2026:

| Responsibility | Owner |
| --- | --- |
| Shared validated detail/integration reads, queue dispatch and display transformations | `CompositionComponent`, inherited by the registry and creation components |
| Encounter/authentication steps, preflight, pending/retry/resume, print and signing | `CompositionCreateComponent` |
| Birth/disability payload construction and type-specific guards | `CompositionCreate` / `CompositionTempDisabilityCreate` instance methods |
| Fields, defaults, validation rules and filling/resetting state | Existing Form classes |
| Cancellation payload and configured timeout | `PatientCompositions` instance methods |
| Native response schemas, identity checks and complete EMAL mirror validation | Existing `app/Classes/eHealth/Api/Patient/Composition.php` client |
| Async status polling, validated reread and expected resource-state confirmation | Existing `CompositionAsyncProcessing` job |
| Local selection, atomic batch import, operation snapshots and persistence | Existing repository factories and repositories |

The three module workflow/payload traits and the new static `SchemaResponseContract` class were removed after migrating their callers. No replacement business service, mapper, workflow trait or custom production static method is introduced. The existing signature infrastructure and framework/repository entry points remain in use.

The wire action names, Form field shape, query parameters, policy guards, KEP modal error handling and session-flash feedback are retained. A remote `DONE` still requires a readable matching document in the expected state before the operation becomes complete. Catalog validation returns native rows; the catalog's JSON settings do not become an alternative source for the legacy EMAL mirror.

**Verification status:** the user explicitly retained the full verification pause on November 12, 2026. Existing test sources were adapted to the component/job ownership changes, but no tests, PHP lint, Pint, frontend build, browser UAT or CI checks were run for this refactor. Earlier validation evidence above belongs to the pre-refactor implementation and does not establish this refactor's correctness. The live acceptance and deployment-configuration items remain open.

## TV39 domain refinement — 2026-11-12

This follow-up changes application sources after `5311c91a6ddb117c0df87f1a5f287ffc5775225b`. It keeps the same Component + Form architecture and existing draft PR #1218. No new application service, workflow trait, DTO or mapper is introduced. The 77-key EMAL contract was already present; this follow-up corrects its consumption, including forced-new versus appended pregnancy periods.

| Finding / requirement | Implemented correction | Owning source |
| --- | --- | --- |
| CREATE/SIGN UI could advance on raw remote DONE | Initial, resumed and polled wizard status comes from the durable local operation. DONE requires matching native details and persistence in the worker. | `CompositionCreateComponent`, `CompositionOperation`, `CompositionAsyncProcessing` |
| A failed/paused local worker was treated as a rejected remote request | Unknown remote outcomes stay pending, including legacy local FAILED records without a confirmed remote FAILED response. Missing token/queue context reports retryable failure. | `CompositionOperation::pending()`, `CompositionAsyncProcessing::dispatchProcessing()` |
| Retry became unavailable after the remote document changed status | Scoped existing-job retry uses `readOperation` policy instead of the pre-write sign/cancel policy. Registry and wizard retry queue only the existing result read. | `CompositionPolicy`, `SubmissionStatus`, registry/wizard actions |
| Repeated writes from concurrent tabs | Creation context and document writes have cache locks; pending accepted requests block another write. Explicit replacements also lock their source chain before KEP. UI accepted state is set before dispatching the worker. | `CompositionCreateComponent`, `PatientCompositions`, operation repository |
| Incomplete native search/encounter pagination | Registry uses the API client's full offset traversal. Oversized pages, repeated UUIDs and present response identifiers inconsistent with requested filters are refused before batch publication. Encounter collection also refuses repeated UUIDs. Native summaries may still omit documented optional subject/author fields. | Composition API, creation/registry components |
| 3.8.2.15.1 cancellation deadline could use stale state | Modal opening and submission read a validated fresh document; selected reason uses its creation date and the normal/replacement timeout. Available reasons use the same guard. An empty dictionary never grants arbitrary codes. | `PatientCompositions`, `CompositionCancellationForm` |
| 3.8.1.10.1 newborn cancellation after integration began | Integration is read at opening and immediately before KEP; any existing newborn integration refuses cancellation. Refused preflight snapshots are not persisted. | `CompositionComponent`, `PatientCompositions` |
| 3.8.2.14.1–14.2 ERLN retry and error display | Only `ERLN / CREATE_ERLN_RECORD / ERROR` grants retry, with fresh FINAL state and escaped exact task message in the confirmation modal. Worker requires that task in the validated integration result. Completion does not claim that ERLN successfully registered the record. | Composition policy/model/job, registry and existing Blade modal |
| 3.8.2.12–12.1 refinement after identification | Search covers both subject UUIDs, excludes the current draft and refuses later FINAL/pending SIGN links. Existing exact SIGNED identification evidence is rechecked before KEP. | `CompositionTempDisabilityCreate` |
| Create Composition manual APPENDS contract mismatch | Only explicit REPLACES is included in create payload. Ordinary overlap and a different eligible source author are allowed; pregnancy's one-time adjacency rule remains, except for a forced new case. | `CompositionTempDisabilityCreate`, its Form |
| Form participant/code tampering | МВТН subject and unidentified flag must match locked patient context. Treatment violation and cancellation reasons must come from their dictionaries; dates/relation codes require their corresponding value. | Component guards and Forms |
| KEP/queue/storage error feedback | Signing validation leaves the modal and error bag available. Accepted requests remain pending if queue dispatch fails. Unexpected local write/queue messages use translations and exception-type diagnostics. | Creation/registry components, existing message consumer |

The current Create Composition reference is `https://e-health-ua.atlassian.net/wiki/x/QwBUFwQ` (API-006-009-0003). Its incapacity-case determination explains automatic `appends`; historical BP-018-0002 and MEDICAL_EVENTS FAQ describe overlapping ordinary periods and one adjacent pregnancy continuation. Those descriptions do not establish the behavior of a live provider deployment. Native response overlays still accept provider-produced `appends`.

### Scenarios pending permission to execute

No tests were added, adapted or executed in this follow-up. The complete execution pause also covers lint, Pint, build, browser UAT, CI queries/reruns and auto-review. The following are an acceptance queue, not passing evidence:

- Remote DONE with missing/wrong patient, author, encounter, episode, type or expected status; a different job ID; late document availability and DB refusal.
- Worker PAUSED/FAILED, expired token, missing session context, failed queue dispatch and retry after the remote write has already changed the document status.
- Two tabs creating/signing/cancelling/retrying the same document and two replacements of the same source; a pending signature of another replacement.
- A repeated or oversized page, filter-context mismatch, native summary without subject/author, later-page refusal and no partial registry publication.
- Cancellation at the deadline/midnight, distinct replacement timeout, invalid/empty reason dictionary, fresh changed creation date/status and newborn integration beginning after modal opening.
- ERLN ERROR for a different task, exact failure message display, FINAL changing before PATCH, absent ERLN creation result and processed retry that still reports integration ERROR.
- Form subject/preperson-flag tampering, orphan relation/violation date, unidentified-to-identified SIGNED pair, a later child under the new person UUID and ordinary overlap/different author with automatic appends.
- Every pregnancy configuration mode, forced new case, one adjacent continuation, repeated/gapped continuation and case length when the new period begins before its selected predecessor.

### Remaining limits

A POST/PATCH transport timeout, or local persistence failure before a durable remote job ID is recorded, still requires reconciliation with eHealth. No undocumented idempotency key or blind repeat is introduced. Cache locks and known pending jobs do not claim to close that remote/local transaction gap. Actual EMAL deployment values, native OFFLINE authentication discrepancy, real KEP/SMS and shared identification/navigation acceptance remain open. The current source cannot be called execution-verified or certified for all TV39 scenarios.
