# Feature Specification: ESОЗ 3.23 — Вимоги до записів про Працівника

**Feature Branch**: `i485_i486_i487_esoz_employee_party_uat` (PR #488)  
**Created**: 2026-07-17  
**Status**: Draft (systematization of existing code + gap inventory)  
**Input**: Технічні вимоги НСЗУ п. 3.23 (реєстрація / оновлення / перегляд / верифікація / деактивація працівників)  
**Related**: Issues #485 #486 #487; PR #488; follow-up #493 (NEW після підпису)

## Overview

~90% логіки вже реалізовано в Livewire Employee / Party Verification / eHealth EmployeeRequest API.  
Мета цієї специфікації — **систематизувати** відповідність п. 3.23 і явно зафіксувати **невиправлені прогалини** перед сертифікаційним UAT.

### Code map (existing)

| Domain | Primary locations |
|--------|-------------------|
| Create / update request + KEP | `app/Livewire/Employee/AbstractEmployeeFormManager.php`, `EmployeeForm.php`, `EmployeeRequestCreate|Edit`, `EmployeeEdit` |
| Policies / roles | `app/Policies/EmployeeRequestPolicy.php`, `EmployeePolicy.php`, `config/scopes/roles.php` |
| Status enum | `app/Enums/Employee/RequestStatus.php` |
| Request list / show | `EmployeeRequestIndex`, `EmployeeRequestShow`, `employee-request-index.blade.php` |
| Employee registry | `EmployeeIndex`, `employee-index.blade.php` |
| Party verification | `PartyVerificationIndex`, `PartyVerify`, `resources/lang/uk/party_verification.php` |
| Deactivation | `EmployeeIndex::deactivate`, `deactivate-modal.blade.php`, `Classes/eHealth/Api/Employee.php` |
| Dict / config | `config/ehealth.php` (`medical_employees`, `employee_type_custom_position_allowed`, `employee_identity_document_types`) |

---

## User Scenarios & Testing *(mandatory)*

### User Story 1 — Реєстрація працівника (Priority: P1)

As OWNER / PHARMACY_OWNER / HR / ADMIN I can form and submit Create Employee Request v2 with party + professional data, sign with KEP, and get clear success/error feedback.

**Why this priority**: Core of 3.23.1.1–1.6.

**Independent Test**: Create new medical employee end-to-end on PRIMARY_CARE LE.

**Acceptance Scenarios**:

1. **Given** allowed role, **When** opening create form, **Then** status is NEW and not user-editable (3.23.1.2.1).
2. **Given** medical employee type, **When** filling form, **Then** educations + specialities (≥1 `speciality_officio`) are required (3.23.1.2.4 / 1.3.5).
3. **Given** valid draft, **When** signing KEP, **Then** request is sent to eHealth and user sees invitation-related success message (3.23.1.5–1.6.1).
4. **Given** eHealth error, **When** create fails, **Then** user can correct data and resubmit (3.23.1.6.2).

**Implementation status**: MOSTLY DONE — see FR-GAP for preview, invitation copy, primary-speciality exactly-one.

---

### User Story 2 — Оновлення активного працівника (Priority: P1)

As OWNER/HR/ADMIN I can create an update request for APPROVED employee with `employee_id`, without changing immutable fields (3.23.1.7).

**Independent Test**: Edit APPROVED doctor; attempt to change tax_id / position / start_date.

**Acceptance Scenarios**:

1. **Given** APPROVED employee, **When** creating update request, **Then** `employee_id` is present in payload.
2. **Given** update draft, **When** viewing form, **Then** tax_id, birth_date, start_date, employee_type, position, primary speciality are locked (UI + backend).
3. **Given** update draft, **When** changing division, **Then** division remains editable.

**Implementation status**: PARTIAL — backend locks tax_id / primary speciality; **UI lock for position/type/start_date broken** (`isCorePositionDataLocked` unused).

---

### User Story 3 — Перегляд запитів і працівників + верифікація (Priority: P1)

As OWNER/HR/ADMIN I can list/filter requests and employees, open details, see verification warnings, confirm/refute death (3.23.2–3.23.3).

**Independent Test**: UAT C — lists, Party Verify warnings, death modal.

**Acceptance Scenarios**:

1. **Given** requests list, **When** viewing, **Then** see inserted_at, PIB, status, id; can filter by status.
2. **Given** employees list, **When** filtering, **Then** can filter by status, division, tax_id, employee_type, verification_status.
3. **Given** NOT_VERIFIED streams, **When** opening party verify, **Then** DRFO/DRACS/DMS warning texts match 3.23.3.2.2.
4. **Given** dracs_death NOT_VERIFIED, **When** confirming/refuting, **Then** reasons are MANUAL_CONFIRMED / MANUAL_NOT_CONFIRMED + comment.

**Implementation status**: MOSTLY DONE for verification/death; PARTIAL for list filters/columns and show professional blocks.

---

### User Story 4 — Деактивація (Priority: P1)

As OWNER/HR/ADMIN I can deactivate APPROVED employee with STOPPED (end_date) or ENTERED_IN_ERROR (no end_date) and correct confirmation copy (3.23.4).

**Independent Test**: Deactivate doctor vs non-doctor; validate end_date bounds.

**Implementation status**: DONE on this branch.

---

### Edge Cases

- Custom free-text `position` only for types in `EMPLOYEE_TYPE_CUSTOM_POSITION_ALLOWED` (ADMIN/HR/RECEPTIONIST).
- Birth certificate not in `EMPLOYEE_IDENTITY_DOCUMENT_TYPES`.
- Dual `speciality_officio=true` must fail validation.
- Legacy local `SIGNED` rows vs eHealth `NEW` after create (see #493).
- `party_verification:details` used for list/details; `:read` stripped from OAuth scopes.

---

## Requirements *(mandatory)*

### Functional Requirements — DONE (existing code)

| FR | TZ | Evidence |
|----|----|----------|
| FR-323-CREATE-FORM | 3.23.1.2.2–2.3 | `EmployeeForm`, `parts/party|position|documents` |
| FR-323-STATUS-NEW | 3.23.1.2.1 | Draft persisted as NEW; no status input |
| FR-323-CUSTOM-POSITION | 3.23.1.2.2 | `config/ehealth.php` + `position.blade.php` |
| FR-323-IDENTITY-DOCS | 3.23.1.3.3 | Filtered document types; no birth cert |
| FR-323-KEP | 3.23.1.5 / 1.8 | Cipher + `EHealthEmployeeRequest::create` |
| FR-323-RESUBMIT | 3.23.1.6.2 | Draft edit policy; signed→new draft path |
| FR-323-WARNINGS | 3.23.3.2.2 | `party_verification.php` warning.* |
| FR-323-DEATH | 3.23.3.4 | `PartyVerify` MANUAL_CONFIRMED / MANUAL_NOT_CONFIRMED + comment |
| FR-323-DEACTIVATE | 3.23.4 | Modal + API end_date rules + doctor/other copy |
| FR-323-TERMINOLOGY | UAT | UI «Працівник» (no «Співробітник» in employee lang) |
| FR-323-SCOPES-DETAILS | 3.23.3 | `party_verification:details` for sync/UI |

### Functional Requirements — GAPS / PARTIAL (unfixed)

| FR | TZ | Severity | Gap |
|----|----|----------|-----|
| **FR-GAP-323-LOCK-UI** | 3.23.1.7 | ~~Critical~~ **FIXED** | Blade uses `isCorePositionDataLocked` for position / employee_type / start_date |
| **FR-GAP-323-DIVISION-LOCK** | 3.23.1.7 | ~~High~~ **FIXED** | `EmployeeEdit` keeps `isPositionDataLocked=false`; division editable |
| **FR-GAP-323-PREVIEW** | 3.23.1.4 | ~~High~~ **FIXED** | Pre-KEP preview modal before signature |
| **FR-GAP-323-INVITE-MSG** | 3.23.1.6.1 / 1.9.1 | ~~High~~ **FIXED** | `employees.sign_success` mentions email invitation |
| **FR-GAP-323-SHOW-MEDICAL** | 3.23.3.2.1 | ~~High~~ **FIXED** | Show uses `medical_employees` config |
| **FR-GAP-323-EMP-FILTERS** | 3.23.3.1.1 | ~~Medium~~ **FIXED** | Filters + display for tax_id and verification_status |
| **FR-GAP-323-REQ-ID** | 3.23.2.1.1 | ~~Medium~~ **FIXED** | Request id/uuid column on requests list |
| **FR-GAP-323-OFFICIO-ONE** | 3.23.1.3.5 | ~~Medium~~ **FIXED** | Exactly one `speciality_officio=true` for medical types |
| **FR-GAP-323-OWNER-FALLBACK** | 3.23.1.1 | ~~Medium~~ **FIXED** | OWNER/PHARMACY_OWNER elevated like ADMIN/HR in policies/UI |
| **FR-GAP-323-STATUS-MODEL** | 3.23.1.2.1 / UAT | ~~Medium~~ **FIXED** | Keep NEW after create; draft = NEW+uuid null; SIGNED = «Надіслано» |
| **FR-GAP-323-DOC-SERIES** | 3.23.1.3.2 | Low | Series+number often combined in one `number` field (PASSPORT) vs separate series UX |

### Non-Goals

- Care plans, declarations, equipment, contracts (covered elsewhere / `specs/001`).
- Rewriting working Create/KEP pipeline from scratch.
- Committing Spec Kit CLI bootstrap under `.specify/` (gitignored).

### Key Entities

- **EmployeeRequest** — local draft / pending / terminal request toward eHealth.
- **Employee** — APPROVED (active) employment record.
- **Party** — personal data + verification streams `drfo`, `dracs_death`, `dms_passport`.
- **Revision** — signed payload snapshot (`PENDING` → `SENT`).

---

## Success Criteria *(mandatory)*

- **SC-001**: Every DONE FR has a UAT checkbox in `quickstart.md` / linked UAT script.
- **SC-002**: Every FR-GAP has a tracked task in `tasks.md` with owner severity.
- **SC-003**: Critical/High gaps (LOCK-UI, DIVISION-LOCK, PREVIEW, INVITE-MSG, SHOW-MEDICAL) resolved or explicitly waived before claiming 3.23 certification.
- **SC-004**: Warning texts for 3.23.3.2.2 remain character-aligned with TZ PDF in `party_verification.php`.

## Assumptions

- Dev login credentials from project rules; PRIMARY_CARE LE available when DB restored.
- eHealth sandbox supports Create Employee Request v2 + party verification details.
- Spec Kit CLI optional; feature docs live under `specs/` (tracked); `.specify/` tooling ignored.
