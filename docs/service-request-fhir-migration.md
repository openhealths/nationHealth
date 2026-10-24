# Module 3.17 — Service Request FHIR migration

Issue: #1005. Initial baseline: company main 041a3346; implementation reconciled with main 39d4a6b7. Care Plan #998 remains a separate draft; this block must not merge or publish its branch to the company repository.

## Design before implementation

The existing ServiceRequestMapper is replaced in place. The canonical representation is the ESOZ Service Request profile with camelCase application keys, Reference for code (service/service_group), object category, Quantity, requesterEmployee/requesterLegalEntity, typed context, ordered basedOn and supporting/reason/permitted/specimen/focus reference collections. Generic HL7 Requester/quantityInteger/category-list output is retired. The existing Fhir facade remains the entry point. Flat form fields are projections and carry the original resource as conversion context so an unrepresented value survives an edit. UUID allocation belongs to draft orchestration; mapping never generates identity or reads the clock. Occurrence values are never shifted, clamped or padded during serialization.

Endpoint create and PreQualify share that resource mapping but have different envelopes. The current create signs the flat service request and singular program; PreQualify wraps service_request and programs. Server-owned fields are excluded from create. Cancel/Recall fetch a fresh raw GET snapshot and add only documented status_reason/explanatory_letter; no case conversion, field filtering, or empty/false removal may touch that snapshot. A failed or mismatching GET prevents signing. Clinical status is updated only after successful job resolution; pending/error is not success.

Persistence remains in ServiceRequestRequestRepository and uses the project's existing Identifier/CodeableConcept/Quantity/Period primitives. Reuse complete primitive methods introduced in #998 as identical shared code; no small new services. New schema changes are additive, guarded for either module installation order. Preserve full coding metadata and ordered reference collections; metadata holds unmodeled resource fields, not a second authoritative raw snapshot. Existing list/filter columns are compatibility projections of the canonical resource. Cross-patient UUID reuse is rejected. External executor caching is distinct from issuer author identity; never attribute an external author to the current employee.

No broad cleanup of the 1,000-line combined ServiceRequest/DeviceRequest lifecycle service: replace the Service Request mapping/integration seams and move newly introduced DB access into the existing repository. DeviceRequest behavior is outside this module. Do not change auth/scopes/shared job infrastructure or Blade/JavaScript.

## Review blocks

1. Mapper contracts and endpoint serialization (synthetic fixtures and regressions).
2. Repository storage, additive schema, import/form/sign context and data conversion.
3. Server-side caller cutover, exact-snapshot cancel/recall, UI specification and operational runbook.

One umbrella PR, three cumulative review PRs; release the complete module together. This continues the previously authorized module-by-module split, not a batch of atomic PRs.

## Source register and discrepancies

Public Confluence content re-fetched 2026-10-19 through its REST API:
- Create API-007-062-0002: page 17572102196, version 52, updated 2026-10-15.
- PreQualify API-007-062-0001: page 17572102185, version 72, updated 2026-10-15.
- Details API-007-062-0006: page 17572167722, version 34, updated 2026-10-15.
- Cancel API-007-062-0007: https://e-health-ua.atlassian.net/wiki/spaces/ESOZ/pages/17572266059, version 38, updated 2026-10-18.
- Recall API-007-062-0008: https://e-health-ua.atlassian.net/wiki/spaces/ESOZ/pages/17572200551, version 28, updated 2026-10-18.
- Use API-007-062-0012: page 17572167739, version 26, updated 2026-10-15.
- Complete API-007-062-0015: page 17572134964, version 32, updated 2026-10-15.

Current Cancel/Recall link to docs.ehealth.gov.ua; anonymous retrieval returned HTTP 403. Older APIary snapshots from the prior audit are structural corroboration, not proof of freshness of that blocked specification. Confluence explicitly requires the complete stored resource for signed lifecycle changes. Preserve raw signed snapshots even if they contain properties not represented by the form. The create JSON schema versus narrative authored_on rules need sandbox confirmation; do not fabricate authored_on. The technical requirements demand explanatory text for cancel/recall even where the API narrative calls it optional.

## Acceptance and cutover gates

Run synthetic mapper round-trip and API body/HTTP failure contracts, precision and idempotent persistence, cross-patient identity, old draft conversion, existing Encounter/Care Plan lifecycle regressions and integration with #998. Use Sail and DatabaseTransactions, fake HTTP, disposable testing database only. No production/development migration or real KEP during implementation.

Before release: backup and restore rehearsal; read-only preflight and complete reimport of registered historical records; explicit write conversion of recoverable drafts; repeat-run/reconciliation; manual KEP and ESOZ UAT; required UI specification items. Historical precision/unknown reference types cannot be guessed. Backend conversion is not 100% module certification.

## Operator runbook

This is an additive schema rollout followed by explicit data reconciliation. Do not run the conversion against a working database during review.

1. Before deployment, make and restore-test a backup including primitives and Service Request tables. Deploy the three review blocks together. Apply the project `update` path; fresh installations use `install`. Both migration entry points share the same implementation. Rollback means a reviewed forward fix or restoration of the matching backup, because narrowing decimal/timestamp columns would lose data.
2. Run `php artisan service-requests:backfill-fhir --person=<localPersonId>` without `--write`. The command reports record counts, local IDs needing remote sync, and conversion errors. It never prints clinical resource contents. Record the output as reconciliation evidence.
3. For `needsRemoteSync`, import complete authorized ESOZ detail responses through the repository with the correct patient context, then rerun preflight. A search summary cannot establish the complete clinical resource or its author. Repeated imports reuse owned primitives. A UUID owned by another patient aborts import.
4. Resolve every ambiguous draft from evidence: actual author/legal entity, typed service or service group, activity and care plan, quantity units, and encounter or episode. Do not infer historical fields from the currently logged-in employee. The write pass converts nothing when any row in that patient's preflight is blocked.
5. Run `php artisan service-requests:backfill-fhir --person=<localPersonId> --write` only for a reconciled patient. The transaction locks and checks each draft again. Rerun it: the second conversion count must be zero, with stable IDs, quantities, references and occurrence values.
6. Compare sample resources before and after persistence, plus form edit and signed create. Explicitly cover service groups, multiple reasons/supporting references, activity plus care plan, external authors, empty collections, decimal quantities, and timezone/fractional timestamps. Registered resources use remote reimport; the command does not silently rewrite their clinical history.
7. Run manual ESOZ/KEP UAT and implement the mandatory items in `service-request-fhir-ui-spec.md` before release. Keep the complete umbrella draft until these gates are satisfied.

Clinical `status` is stored separately from execution/program progress. Legacy lists still project `in_progress`; canonical serialization retains the actual clinical `active`. Complete writes clinical `completed` only after successful remote resolution. Pending/error jobs cannot advance local clinical state.

## Explicit remaining boundaries

- This implements the ESOZ profile, whose wire keys differ from generic HL7 R4 JSON. It does not claim generic HL7 server interoperability or 100% module certification.
- Complete serializes `completed_with` correctly and preserves existing patient ownership checks. Clinical EMZ success, actual referral linkage, service-group membership and current dictionary validation remain separate business/UAT work.
- Cancel/Recall use the fresh full raw resource. Their current external specification links returned 403 anonymously; confirm the contract in the target ESOZ environment before enabling release.
- Cancel usage is blocked before HTTP because the available Release documentation is draft/deprecated. It must never fall back to unsigned clinical Cancel. The UI specification requires an unavailable-action explanation until a verified PROD contract exists.
- Existing forms do not yet expose all canonical fields. Missing cancellation explanation/recall reason controls, executor-result selection and the other mandatory UI changes are a release gate, not implemented frontend work.
- Standalone DeviceRequest from Encounter belongs to another PR/chat. Medication Requests and Medical Conclusions remain subsequent modules.
- Compatibility with Care Plan #998 includes identical primitive helpers and guarded Coding migration additions. Publish each module independently; test their integration without copying #998 into this main-based umbrella.

## Verification record

- Initial Sail referral regression before compatibility hardening: 107 tests, 553 assertions, zero failures (existing PHP 8.5 PDO deprecations are reported separately).
- Final schema: fresh `install --no-interaction` and `update --no-interaction` succeeded in an additional disposable database. Transactional rehearsals verified both Care Plan → Service Request and Service Request → Care Plan migration orders, including shared Coding fields and clinical status.
- Legacy drafts cannot enter the signing path until explicit canonical conversion/reconciliation. Pending/error mutation responses without job links cannot advance local status. Scientific notation below supported precision and decimals not representable by the JSON numeric contract are rejected instead of rounded.
- The wider owned-domain suite and final focused contracts are recorded in the umbrella PR after completion. Real KEP and ESOZ UAT remain manual gates.

## Care Plan #998 integration evidence

A private local integration branch combined the current Care Plan and Service Request umbrellas. Its 49 contract tests passed (206 assertions), followed by the normalized plan/activity → referral → PreQualify → signed-create-content scenario (11 assertions). No real signature or clinical API write was performed. Both migration installation orders also passed in the disposable rehearsal database.

That scenario exposed and fixed reads from obsolete activity scalars: Service Request now reads the actual service/service_group identifier, program and Quantity units from the normalized Activity resource. Both Care Plan and Encounter creation retain explicit dateTime values before persistence rather than reducing them to a calendar date. The regression lives in `ReferralSignPayloadTest`; it is explicitly skipped when the complete #998 module is absent, and executes when both modules are present.

The private merge is retained locally as `i1005_referral_care_plan_compatibility`; it is not a remote branch or part of this umbrella. When integrating both modules, resolve their overlapping referral files deliberately: keep Service Request `updateClinicalStatus` (a generic status write would leave canonical status stale), retain patient-scoped activity lookup, keep the repository-only Activity updates from #998, and retain one signature-service test mock. These are integration resolutions, not authorization to merge either company PR.
