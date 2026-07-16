# Official References — Care Plans Domain

**Updated**: 2026-07-17  
**Source notebook (user)**: [NotebookLM](https://notebooklm.google.com/notebook/0b1b71c9-c4ef-42e6-a2b4-22da174c1d36) — вимагає Google login; у git зберігаємо витягнуті правила нижче.

## Canonical links

| Topic | Doc | URL |
|-------|-----|-----|
| Create Care Plan | API-007-005-0001 | https://e-health-ua.atlassian.net/wiki/spaces/ESOZ/pages/17571905622/REST+API+Create+Care+Plan+API-007-005-0001 |
| Create Care Plan (Apiary) | ESOZ Apiary | https://esoz.docs.apiary.io/#reference/clinical-info/care-plan/create-care-plan |
| Create Care Plan Activity | EH wiki | https://e-health-ua.atlassian.net/wiki/spaces/EH/pages/17052598297/Create+Care+Plan+Activity |
| Complete Care Plan | API-007-005-0006 | https://e-health-ua.atlassian.net/wiki/spaces/ESOZ/pages/17571872867/REST+API+Complete+Care+Plan+API-007-005-0006 |
| Complete Care Plan Activity | API-007-006-0006 | https://e-health-ua.atlassian.net/wiki/spaces/ESOZ/pages/17571905639/REST+API+Complete+Care+Plan+Activity+API-007-006-0006 |
| Cancel Care Plan Activity | API-007-006-0005 | https://e-health-ua.atlassian.net/wiki/spaces/ESOZ/pages/17570759126/REST+API+Cancel+Care+Plan+Activity+API-007-006-0005 |
| Create Approval | CSI-1323 | https://e-health-ua.atlassian.net/wiki/spaces/ESOZ/pages/17999298941/AR+RC_+CSI-1323+_Create+approval |
| PreQualify MRR | API-005-044-0001 | https://e-health-ua.atlassian.net/wiki/spaces/ESOZ/pages/17570660609/REST+API+PreQualify+Medication+Request+Request+API-005-044-0001 |
| ePrescription hub | EH wiki | https://e-health-ua.atlassian.net/wiki/spaces/EH/pages/16837083159/ePrescription |
| Medication Request Requests (Apiary) | under Care Plan ref | https://esoz.docs.apiary.io/#reference/clinical-info/care-plan/create-care-plan (anchor to medication-request-requests) |

**Alternate Apiary for activity**: https://medicaleventsmisapi.docs.apiary.io/#reference/care-plan/create-care-plan-activity/create-care-plan-activity

---

## Extracted rules (must drive MIS)

### Create Care Plan (API-007-005-0001)

- `POST /api/patients/{patient_id}/care_plans`, scope `care_plan:write`, **async** (job).
- LE types: MSP, PRIMARY_CARE, OUTPATIENT.
- Author: `employee_type` ∈ `CARE_PLAN_AUTHOR_EMPLOYEE_TYPES_ALLOWED` + specialty ∈ category specialty allow-list.
- **Signed with DS**; signed content → media storage.
- Plan is created **without activities**; activities via separate Create Activity.
- Persons and prepersons allowed.
- Key fields validated: subject, author, category, encounter, addresses, terms_of_service (`PROVIDING_CONDITION`), period, based_on, part_of, supporting_info, contributors, id, status, intent, **inform_with**.

### Create Care Plan Activity

- `POST /api/patients/{patient_id}/care_plans/{care_plan_id}/activities`, scope `care_plan:write`, **async**.
- **One activity per request**; **DS required**; author DRFO must match party.tax_id.
- Caller MUST have **Approval write** on that care plan; same LE as `managing_organisation`.
- Plan not final; `period.end >= today`; patient active & verified.
- `detail.kind` ∈ `eHealth/activity_kinds`: `medication_request` | `service_request` | `device_request`.
- Product rules differ by kind (INNM_DOSAGE / service|service_group / device_definition or codeable_concept).
- No duplicate scheduled/in_progress activity for same medication (or same service/group for SR).
- Quantity/daily_amount/units strict per kind; remaining_quantity seeded from quantity.
- Scheduled timing/period/string — mutually exclusive; within care plan period.

### Complete Care Plan (API-007-005-0006)

- `PATCH .../care_plans/{id}/actions/complete`, scope `care_plan:write`, async.
- **Complete performs WITHOUT digital signature.**
- Requires write Approval (author or same managing_organisation employee with write approval).
- `status_reason` from `eHealth/care_plan_complete_reasons`.
- All activities in **final** status; **at least one** activity `completed`.
- Else 409: scheduled/in-progress activities OR no completed activity.

### Approvals (CSI-1323)

- Scope `approval:create`; **async**.
- Resource `care_plan`: no other entities in same approval; `granted_to` = employee.
- `access_level=write` on care_plan: only if `managing_organization` = granted employee’s LE.
- Write on care_plan grants: create activities; cancel medication requests; recall/cancel service requests based on care plan.
- INPATIENT care plan + same LE as managing_org → skip person auth_method / urgent null.
- Else: OTP (SMS) or offline; null auth → 409; `authorize_with` optional (else default method).
- Unverified approvals expire (config, e.g. 12h).

### PreQualify MRR (API-005-044-0001)

- `POST /api/medication_request_requests/prequalify`, scope `medication_request_request:write`, **sync**.
- Does **not** create entities; returns VALID/INVALID per submitted program + reason.
- `intent=plan` → cannot be qualified / not for dispense.
- `intent=order` → can be dispensed; full program compliance checks including care_plan + activity, diagnosis, employee, period, context, person, declaration, provision.

### ePrescription hub

Index of MR/MD APIs, device requests, business processes — use as navigation, not single contract.
