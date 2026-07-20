# Research: ESОЗ 3.23 vs PR #488 codebase

**Date**: 2026-07-17  
**Branch**: `i485_i486_i487_esoz_employee_party_uat`  
**Method**: Static code audit against TZ PDF §3.23 (agent explore + file reads)

## Findings summary

| Area | Verdict |
|------|---------|
| Create form + dicts + custom position | Strong |
| KEP + Create Employee Request v2 | Strong |
| Party verification warnings + death modal | Strong (MANUAL_CONFIRMED / MANUAL_NOT_CONFIRMED) |
| Deactivation STOPPED / ENTERED_IN_ERROR | Strong |
| Update immutable-field UI locks | **Broken** (`isCorePositionDataLocked` unused) |
| Pre-KEP preview | Missing |
| Invitation success copy | Missing |
| Employee list verification filters | Missing |
| Show professional data for non-DOCTOR medical types | Missing |

## Top unfixed defects (severity)

1. **Critical** — Update draft: position / employee_type / start_date not locked in UI despite PHP flag.
2. **High** — EmployeeEdit locks division via `isPositionDataLocked`.
3. **High** — No preview before KEP (3.23.1.4).
4. **High** — Success message omits email invitation notice (3.23.1.6.1).
5. **High** — Show professional blocks only for DOCTOR.
6. **Medium** — No tax_id / verification_status filters on employees list.
7. **Medium** — Requests list without request id.
8. **Medium** — No «exactly one» primary speciality rule.
9. **Medium** — OWNER/PHARMACY_OWNER policy fallback incomplete.
10. **Medium** — Post-create local SIGNED vs eHealth NEW (track #493).

## Decisions

- Document DONE vs GAP in `spec.md`; fix in task phases, not a rewrite.
- Prefer #493 approach for status model over remapping SIGNED label to «Новий».
- Keep Spec Kit tooling in `.specify/` (gitignored); persist feature docs in `specs/002-…`.

## Open questions

- Is a dedicated preview screen required, or is read-only show-before-sign modal sufficient for 3.23.1.4 acceptance?
- Should invitation wording be exact NSZU template text (need PDF extract) or paraphrased UA message?
