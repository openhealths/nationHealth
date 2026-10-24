# Combined clinical FHIR UAT

Integration branch: `i1008_clinical_fhir_uat`, issue #1008. Updated against company main `b70debf1` on 2026-10-24, including merged frontend PR #1028. This branch is for combined manual acceptance; do not merge it in place of the module PRs.

## Review and merge order

1. #1022 — shared complete Identifier/CodeableConcept/Period helpers, legacy Coding compatibility, configurable clinical date conversion. Review the explicit shared diff first.
2. #998 — TV 3.10 CarePlan/Activity: mapping, persistence, integration/workflows; reuse fork review blocks #17–20.
3. #1006 — TV 3.17 referrals: mapping, persistence, workflows; reuse fork review blocks #21–23.
4. #1011 — TV 3.9 prescriptions: mapping, persistence, signing/workflows; reuse fork review blocks #24–26.
5. TV 3.8 Medical Conclusions — registry/wizard fixes and complete local detail fidelity; delivered after the prescription umbrella so the shared CI runs the complete stack.

Review blocks are stacked where a real dependency exists. Company umbrellas target main and temporarily show prerequisite commits until earlier PRs merge. Merge order is mandatory; deploy the complete reviewed batch. Cross-module issuing flows are accepted on the combined branch, not on an incomplete middle slice. The release manifest records exact branch/commit dependencies. All published heads belong to Mefizz/ohealth.

## Included behavior

- CarePlan: exact periods, typed references and complete codings, integer device activity quantity, product name, encounter date and episode context, safe approvals and no accidental legacy registry crash.
- Referral: patient/encounter scoped create page, localized dates/errors, complete FHIR import and signing, valid transfer division search without artificial map bounds, translated same-name facility type, top-right flash on lookup failure or empty eligible results. Speciality describes the future recipient, not the author.
- Prescription: patient/encounter scoped create page, general prescription program restored, catalog dose units and complete maxima, standard dates, fresh untouched native signing detail; seed optional unless present. A pending MRR can be resumed without another create. Clinical inputs are locked until signing/cancellation. Accepted signatures persist before a full MR detail GET/import; GET-only recovery is available in the patient registry.
- Medical Conclusions: registry translations/sync, encounter preselection, complete type/category and Identifier codings, explicit false extensions, event codes/additional events, UTC fractional timestamps, new install/update precision migration.
- Display timezone is configurable with `APP_DISPLAY_TIMEZONE` (default Europe/Kyiv). UI timestamp conversion and local-day queries use the configured zone; date-only clinical fields retain calendar meaning. Existing global runtime/storage conventions have not been globally migrated.

ClinicalImpression and the separate standalone DeviceRequest implementation are outside this release. Existing main changes remain intact. FHIR here means the application profile with normalized typed references and lossless structured fields, not a generic HL7 R4 export or a claim of complete ESOZ certification.

## Deployment and manual acceptance

Back up the production DB using the normal procedure. Apply both install/update migrations using the project deployment commands; preserve all new precision and metadata columns. Old records are not a blocker for the requested new-record rollout, but a summary cannot be treated as a full FHIR detail.

Use the combined branch to run the following on both +pmd35 and +outp35, with synthetic preprod patients. Every unexecuted case stays not run. Human tester supplies KEP/OTP; reconcile remote state before repeating a mutation with unknown outcome.

1. Confirm clinic/role/patient. Check each registry, filters, translations and sync.
2. Create a completed encounter. Verify its local date, episode and translated transfer facility type; choose the intended actual facility.
3. CarePlan: create/reopen, approvals, all three Activity kinds, quantity/unit/period and parent links; user-sign where required.
4. Referral: create from Encounter and Activity; future start time, standard inputs, transfer destination/divisions, speciality; sign, refresh, then designated lifecycle actions. Check wrong patient/clinic cannot act.
5. Prescription: general and reimbursed programs, product selection, quantity and dose units, create NEW, resume same MRR, user-sign; verify separate SIGNED MRR and full canonical MR. Inject a detail GET failure in automated tests; browser recovery must not ask for another signature.
6. Medical Conclusion: navigate from Encounter without reselecting it, complete creation/approval/signing, sync; verify typed references, event and explicit false flags survive reread.
7. Check denied/expired session, validation errors, pending/failed jobs and missing details show no false clinical success.

## Evidence and remaining acceptance boundaries

The user successfully signed one SMD general-program prescription in preprod; local MRR and MR have separate identities. That does not cover all programs, all roles or the new hydration recovery path.

The nightly date-only MRR/Encounter validation, positive transfer to a clinical division, and the prepared new SMD encounter still require completed UAT. Independent MR list search returned 403 in one session; do not broaden shared OAuth/scopes without proving the cause. Signing performance remains outside this pass at the user’s request.

Automated results are recorded in the release manifest after execution. Static/lint checks do not replace browser/KEP or ESOZ acceptance.
