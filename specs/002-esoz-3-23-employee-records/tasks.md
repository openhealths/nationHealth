# Tasks: ESОЗ 3.23 Employee Records gaps

**Feature**: `002-esoz-3-23-employee-records`  
**Date**: 2026-07-17

## Phase 0 — Spec Kit (this PR / docs)

- [x] T000 Author `specs/002-esoz-3-23-employee-records/spec.md` from TZ 3.23 + code audit
- [x] T001 Plan / tasks / research / analyze / checklist / quickstart
- [x] T002 Ensure `.specify/` is gitignored; feature specs remain under `specs/`

## Phase 1 — Critical / High (blocking certification)

- [ ] T010 **FR-GAP-323-LOCK-UI**: Wire `isCorePositionDataLocked` in Blade for position, employee_type, start_date on update drafts
- [ ] T011 **FR-GAP-323-DIVISION-LOCK**: Keep division editable on EmployeeEdit (do not set `isPositionDataLocked` for division)
- [ ] T012 **FR-GAP-323-INVITE-MSG**: Success flash must mention automatic email invitation (3.23.1.6.1 / 1.9.1)
- [ ] T013 **FR-GAP-323-SHOW-MEDICAL**: Show educations/qualifications/specialities/science_degree for all `medical_employees`
- [ ] T014 **FR-GAP-323-PREVIEW**: Add pre-KEP read-only review of request fields (3.23.1.4)

## Phase 2 — Medium

- [ ] T020 **FR-GAP-323-EMP-FILTERS**: Employees list filters + columns for tax_id and verification_status
- [ ] T021 **FR-GAP-323-REQ-ID**: Show request id on EmployeeRequestIndex
- [ ] T022 **FR-GAP-323-OFFICIO-ONE**: Validate exactly one `speciality_officio=true` for medical types
- [ ] T023 **FR-GAP-323-OWNER-FALLBACK**: Policy/UI elevate OWNER + PHARMACY_OWNER like ADMIN/HR when scopes incomplete
- [ ] T024 **FR-GAP-323-STATUS-MODEL**: Integrate / merge #493 (keep NEW after eHealth create)

## Phase 3 — Low / polish

- [ ] T030 **FR-GAP-323-DOC-SERIES**: Clarify PASSPORT series+number UX vs DocumentNumber rule
- [ ] T031 Align delete-draft modal wording («чернетка») with status labels
- [ ] T032 PHPUnit coverage for T010–T022
- [ ] T033 Full manual UAT via `quickstart.md`

## Out of scope

- Rewriting Create Employee Request pipeline
- Contracts / care plans
