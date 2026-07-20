# Feature Specification: ESОЗ Compliance — Employee, Party, Contracts (PRIMARY_CARE)

**Feature Branch**: `testing/esoz-combined-uat` (UAT) / follow-up fix branches for Remaining  
**Created**: 2026-07-16  
**Status**: Draft (superseded for full §3.23 by `specs/002-esoz-3-23-employee-records` on PR #488)  
**Input**: ESОЗ tester conclusions 3.1.1, 3.1.5, 3.23 + PRs #462 #474 #476 #480  

> **Note**: For TZ §3.23 systematization + remaining gaps on branch `i485_i486_i487_esoz_employee_party_uat`, use **[002-esoz-3-23-employee-records](../002-esoz-3-23-employee-records/spec.md)**. Death reasons `MANUAL_CONFIRMED` / `MANUAL_NOT_CONFIRMED` are DONE there (FR-GAP-001 below is obsolete on #488).

## User Scenarios & Testing *(mandatory)*

### User Story 1 — Unverified party informational block (Priority: P1)

As a doctor (not OWNER/HR/ADMIN), when eHealth returns 403 «Party is not verified» under `BLOCK_UNVERIFIED_PARTY_USERS=true`, I see the official multi-paragraph informational message (ДПС/ДРАЦСГ + HR contact), not a generic processing error.

**Why this priority**: Blocks certification for 3.1.1.4.

**Independent Test**: Trigger employee request while party unverified; assert flash/UI text matches PDF.

**Acceptance Scenarios**:

1. **Given** BLOCK_UNVERIFIED_PARTY_USERS=true and eHealth 403 with «Party is not verified», **When** a non-admin role performs an employee write action, **Then** UI shows the full `party_not_verified` text.
2. **Given** a different 403 message, **When** the same handler runs, **Then** `party_not_verified` is NOT shown.

**Implementation status**: DONE — PR #476 (on combined branch).

---

### User Story 2 — PRIMARY_CARE NHSU contract visibility & controls (Priority: P1)

As OWNER/HR of a PRIMARY_CARE legal entity, I can view contract/request details with id_form, real inserted_at, localized payment method, period on index, «Капітація» labeling, and I cannot approve reimbursement submissions as OWNER when restricted.

**Why this priority**: Closes 3.1.5 administrator remarks 1–6.

**Independent Test**: UAT section D against PRIMARY_CARE LE.

**Acceptance Scenarios**:

1. **Given** a synced contract, **When** I open details, **Then** id_form and inserted_at from eHealth are shown.
2. **Given** nhs_payment_method FORWARD/BACKWARD, **When** I view NHS customer block, **Then** I see «Попередня оплата» / «Післяплата».
3. **Given** PRIMARY_CARE reimbursement request in list, **When** I view type column, **Then** label is «Капітація».
4. **Given** OWNER on PRIMARY_CARE reimbursement request, **When** I open show, **Then** approve/submit is denied/hidden.

**Implementation status**: DONE — PR #462 (on combined branch).

---

### User Story 3 — Employee registry & party verification compliance (Priority: P1)

As OWNER/HR/ADMIN I can manage employees per 3.23: create/update with correct statuses/documents, lock RNOКПП, deactivate with end_date, access lists, verify death, and see official verification warnings for DRFO/DRACS/DMS.

**Why this priority**: Core failed module in tester conclusions.

**Independent Test**: UAT section C (+ C.17 warnings).

**Acceptance Scenarios** (selected):

1. **Given** no_tax_id, **When** choosing identity document, **Then** PERMANENT_RESIDENCE_PERMIT is available.
2. **Given** edit of existing party, **When** tax_id is locked, **Then** UI disables field and backend restores original tax_id.
3. **Given** dracs_death NOT_VERIFIED, **When** confirming/refuting death, **Then** payload uses MANUAL_CONFIRMED / MANUAL_NOT_CONFIRMED with VERIFIED status and comment.  
   → **GAP on combined** (still MANUAL_DECEASED / MANUAL_NO_DEATH_RECORD).
4. **Given** NOT_VERIFIED on drfo/dracs_death/dms_passport, **When** viewing verification UI, **Then** warning texts match PDF 3.23.3.2.2 literally.  
   → DONE via #480 on combined.
5. **Given** NEW employee request, **When** viewing badge, **Then** label is «Новий».  
   → **GAP** (shows «Чернетка»).

**Implementation status**: MOSTLY DONE — PR #474 + #480; Remaining gaps listed below.

---

### Edge Cases

- Party verification update allowed for NOT_VERIFIED and VERIFICATION_NEEDED; modal status locked to VERIFIED (`7b35c1d9`).
- Missing party:read scopes hide Party Verification menu (#474).
- Merge conflict resolution on combined: prefer full #476 `party_not_verified` text.

## Requirements *(mandatory)*

### Functional Requirements — Already implemented (baseline PRs)

- **FR-DONE-001** (F-311-4): System MUST show full 3.1.1.4 message on 403 Party is not verified. `#476`
- **FR-DONE-002** (F-315-1..6): System MUST satisfy all six 3.1.5 administrator remarks. `#462`
- **FR-DONE-003** (F-323-* majority): System MUST provide Admin/HR access, deactivation UX, RNOКПП lock, custom position, dual-speciality translation, PERMANENT_RESIDENCE_PERMIT, party verification 500 guard. `#474`
- **FR-DONE-004** (F-323-322): System MUST show PDF-aligned warnings for drfo, dracs_death, dms_passport. `#480`
- **FR-DONE-005**: Party death modal status MUST stay VERIFIED; update allowed for VERIFICATION_NEEDED. `#474` `7b35c1d9`

### Functional Requirements — Remaining gaps

- **FR-GAP-001** (F-323-11): System MUST send `verification_reason` `MANUAL_CONFIRMED` / `MANUAL_NOT_CONFIRMED` (not MANUAL_DECEASED / MANUAL_NO_DEATH_RECORD). Comment required per issue notes.
- **FR-GAP-002** (F-323-2): System MUST display NEW employee-request status as «Новий» (not «Чернетка»).
- **FR-GAP-003** (F-323-4): System MUST replace remaining Employee-module «Співробітник» UI strings with «Працівник».
- **FR-GAP-004** (F-323-rec): Employee search MUST match full name tokens order-independently.
- **FR-GAP-005** (F-323-1 PARTIAL): Confirm birth-certificate document validation messages are fully localized under UAT; fix residual raw paths if found.

### Non-Goals

- PR #481 EmployeeRole mapMany revive (excluded; #407 fixed create-role empty speciality 500).
- Care plan / ePrescription / equipment / declarations modules.

### Key Entities

- **Employee / EmployeeRequest**: employment record and draft/signed requests to eHealth.
- **Party**: personal data + verification streams (drfo, dracs_death, dms_passport).
- **Contract / ContractRequest**: NHSU agreements for PRIMARY_CARE (capitation/reimbursement labeling).

## Success Criteria *(mandatory)*

- **SC-001**: All DONE finding_ids PASS on `testing/esoz-combined-uat` via UAT script sections B, C (non-gap), D.
- **SC-002**: Remaining FR-GAP-001..004 each have a follow-up issue/PR before claiming full 3.23 compliance.
- **SC-003**: No duplicate overlapping PRs required for manual UAT — single combined branch is enough for smoke.
- **SC-004**: Certification informational texts match PDF extracts character-for-character in critical paragraphs (3.1.1.4 and 3.23.3.2.2).

## Assumptions

- Sail is running; PRIMARY_CARE legal entity id=1 available with dev login credentials from project rules.
- eHealth sandbox can reproduce 403 party-not-verified and verification streams for UAT (or stubs).
- Spec Kit CLI may be installed later; artifacts already follow Spec Kit layout.
