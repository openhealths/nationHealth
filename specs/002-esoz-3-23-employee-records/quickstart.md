# Quickstart / Manual UAT — ESОЗ 3.23 (PR #488)

**Повний алгоритм:** [uat-algorithm-3-23.md](./uat-algorithm-3-23.md)

**Branch**: `i485_i486_i487_esoz_employee_party_uat`  
**Login**: `/dev/login` — credentials from project rules (PRIMARY_CARE + OUTPATIENT); for 3.23 prefer OWNER/HR/ADMIN.

## Preconditions

- [ ] Sail up; DB with LE + employee / party_verification scopes
- [ ] `vendor/bin/sail artisan config:clear` before PHPUnit
- [ ] Roles OWNER / HR / ADMIN (or PHARMACY_OWNER) available

## Smoke checklist (all gaps closed)

### A. Create (3.23.1)

- [ ] A1 Create form opens for elevated roles
- [ ] A2 Draft status «Новий»; no user-editable status field
- [ ] A3 Position / type / start_date / division filled
- [ ] A4 Party + identity doc; PASSPORT = single «Серія/номер документа» field
- [ ] A5 Medical: exactly one primary speciality; two primaries fail
- [ ] A6 «Завершити та підписати» → **preview modal** → KEP
- [ ] A7 Success flash mentions **email invitation**
- [ ] A8 After submit: uuid present; edit/delete hidden (pending NEW)

### B. Update APPROVED (3.23.1.7)

- [ ] B1 tax_id / birth_date locked
- [ ] B2 position / employee_type / start_date locked
- [ ] B3 division editable
- [ ] B4 Preview → KEP → invitation flash

### C. Lists (3.23.2–3.23.3)

- [ ] C1 Requests list shows **id/uuid**, PIB, datetime, status filter
- [ ] C2 Employees list filters: tax_id, verification_status (+ status, division, type)
- [ ] C3 Party card shows tax_id + verification_status
- [ ] C4 Show SPECIALIST/ASSISTANT: professional blocks visible

### D. Verification & deactivate

- [ ] D1 DRFO/DRACS/DMS NOT_VERIFIED warnings match TZ
- [ ] D2 Death confirm/refute: MANUAL_CONFIRMED / MANUAL_NOT_CONFIRMED + comment
- [ ] D3 STOPPED needs end_date in [start_date, today]
- [ ] D4 ENTERED_IN_ERROR omits end_date
- [ ] D5 DOCTOR confirmation mentions declarations
