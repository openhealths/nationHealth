# Quickstart / Manual UAT — ESОЗ 3.23 (PR #488 branch)

**Branch**: `i485_i486_i487_esoz_employee_party_uat`  
**Login**: `/dev/login` — credentials from project rules (PRIMARY_CARE + OUTPATIENT)

## Preconditions

- [ ] Sail up; DB restored with LE + employee scopes
- [ ] `vendor/bin/sail artisan config:clear` before tests
- [ ] Roles OWNER or HR/ADMIN available

## A. Create employee request (3.23.1)

- [ ] A1 Open create → status not editable; draft behaves as NEW
- [ ] A2 Fill position / type / start_date / division
- [ ] A3 Party: names, birth_date, gender, tax_id or no_tax_id + identity doc, email, phones
- [ ] A4 Medical type: education + specialities; try two primary → validation error
- [ ] A5 Sign KEP → success; **check whether invitation wording is shown** (expect GAP until T012)
- [ ] A6 Force API error → can edit and resubmit

## B. Update APPROVED employee (3.23.1.7)

- [ ] B1 Open edit for APPROVED → `employee_id` path
- [ ] B2 Confirm tax_id / birth_date locked
- [ ] B3 Confirm position / type / start_date **locked** (expect FAIL until T010)
- [ ] B4 Confirm division **editable** (expect FAIL until T011)

## C. Lists & verification (3.23.2–3.23.3)

- [ ] C1 Requests list: PIB, datetime, status filter; note missing id (GAP T021)
- [ ] C2 Request details show status + inserted_at
- [ ] C3 Employees list filters; note missing tax_id / verification_status (GAP T020)
- [ ] C4 Party verify: DRFO/DRACS/DMS NOT_VERIFIED warnings match TZ
- [ ] C5 Death confirm/refute + comment → MANUAL_CONFIRMED / MANUAL_NOT_CONFIRMED

## D. Deactivation (3.23.4)

- [ ] D1 STOPPED requires end_date in [start_date, today]
- [ ] D2 ENTERED_IN_ERROR sends no end_date
- [ ] D3 DOCTOR vs other confirmation texts

## E. Show professional data

- [ ] E1 Open show for SPECIALIST/ASSISTANT — professional blocks visible (expect FAIL until T013)
