# Contract: Medication Requests (електронні рецепти)

## Medication Request Request (MRR)

| Operation | Method | Path | Notes |
|-----------|--------|------|-------|
| PreQualify | POST | `/api/medication_request_requests/prequalify` (API-005-044-0001) | Sync; only programs in payload; `intent=plan` cannot be qualified |
| Create | POST | `/api/medication_request_requests` (або patient-scoped еквівалент — перевірити Apiary) | API-005-044-0002 |
| Sign | PATCH | `/api/medication_request_requests/{id}/actions/sign` | API-005-044-0006 |
| Reject MRR | PATCH/POST | Reject MRR API-005-044-0007 | NEW → REJECTED |

## Medication Request (MR)

| Operation | Method | Path | Notes |
|-----------|--------|------|-------|
| Search/Get | GET | `/api/patients/{id}/medication_requests...` | |
| Reject MR | per API-005-043-0006 | ACTIVE → REJECTED + `reject_reason_code` | **Not** business-cancel without reject reason dict |
| Resend OTP | POST | `.../actions/resend_otp` | |

## Status model

- MRR: NEW → SIGNED | REJECTED | EXPIRED  
- MR: ACTIVE → COMPLETED | REJECTED | EXPIRED  

## Create MRR key fields

- person_id, employee_id, division_id
- medication_id (INNM_DOSAGE), medication_qty
- medical_program_id
- intent (`order`|`plan`), category
- started_at / ended_at
- based_on: **care_plan** + **activity**
- context: encounter when required
- dosage_instruction (SNOMED route, max_dose_* structures)
- inform_with when notifying patient

## Gap vs current code (must close)

| Topic | Target | Current risk |
|-------|--------|--------------|
| Prequalify | Required before create for order | Missing / incomplete |
| Reject | Reject MRR/MR | cancel action used |
| based_on | care_plan + activity | legacy care_plan_activity in some mappers |
| Lifecycle service | Full createDraft/sign/reject | Thin print/SMS only |

## Client

- `App\Classes\eHealth\Api\Patient\MedicationRequest`
- Target: expand `MedicationRequestLifecycleService`
