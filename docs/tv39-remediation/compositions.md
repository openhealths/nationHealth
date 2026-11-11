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

`DrivesCompositionWizard` enforces the selected current employee contract and own finished encounter, configured period/category/extensions, author/custodian, separate subject and incapacitated focus, native authentication metadata and fresh native encounter diagnoses. A fresh validated `GET /api/persons/{id}/authentication_methods` response is required once per distinct identified subject/focus at preflight. API-010-051-0002 checks `status=active` and `is_active=true` before 200, so relying on that successful read is an inference from its documented business precondition. A valid empty method list attests eligibility, not an existing method, and no person status field is invented or persisted. Read errors and malformed bodies block creation before POST/storage/queue effects. Preperson status is checked from its actual local field. Care categories retain the child/patient as `subject` and select the caregiver as `section.focus`; they do not reassign the patient record. Pregnancy offers every configured initial/continuation duration.

## Native responses and asynchronous results

Composition clients validate native detail/search/job/integration/print responses before mapping, storage, UI publication or completion decisions. Delegating methods retain response validation. Creation, signing, cancellation and ERLN retry retain their asynchronous operation records; a remote DONE job is confirmed locally only after a full native document is read and its identifier, subject, author, encounter, type and expected status match the operation. Malformed responses or mismatched context leave the operation pending and retryable. A search fallback reads all offset pages and refuses duplicate page results or ambiguous matches.

The published native search summary contains identifier/status/title/type/date/encounter/episodeOfCare and may omit author and subject. Patient-wide search supplies the subject context; a focus search without subject never assigns a document to the viewed patient. Registry enrichment validates full native details in the exact patient/composition/episode/encounter context before adopting an unknown author. Known author/type still must match. If detail contains episodeOfCare, that identifier must match too. Every page and readable full detail is validated before a single storage transaction publishes the batch; a later invalid response or storage refusal leaves earlier records and relations unchanged. Search-only permission does not grant a full detail read or fabricate author metadata.

Signing reads the full native document again, preserves it for KEP, and rechecks configured author/category/participant rules and signing deadline. A field or guard failure leaves the signature modal open and displays translated feedback through session flash and the established message consumer. Post-identification refinement rechecks the exact SIGNED merge pair immediately before KEP.

Continuation rereads the previous FINAL document, requires the same author/category/subject/focus, refuses overlap and walks earlier links for configured total case length and cycles. Pregnancy continuation starts on the next day and is allowed only once. Refinement reads every search page and full native details for potential later links, refusing an earlier link or missing read context. The previous record must belong to the same patient or the exact signed identified/preperson pair.

## TV39 mapping

| Requirement | Implementation / evidence |
| --- | --- |
| 3.8.1.1, 3.8.2.1 | Existing `CompositionPolicy` role/entity pair plus current linked, started author selection in `User`; policy and workflow tests include SMD specialist, PMD doctor, unavailable roles and assistant authoring/sign/cancel denial. |
| 3.8.1.5.1–3.8.1.5.2.1, 3.8.2.5.1–3.8.2.5.3 | Own encounter, required payload/context and dynamic preflight in `DrivesCompositionWizard`, newborn identity in `CompositionCreate`, subject/focus care flow in `CompositionTempDisabilityCreate`. |
| 3.8.2.5.4 | `CompositionPregnancyPeriodMode`, configuration-driven choices and native `PREGNANCY` overlay; valid duration, next-day continuation, gap and repeated continuation tests. |
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
