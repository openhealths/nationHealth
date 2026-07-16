# Report: Medium fixes — ESОЗ 3.23 (PR #488)

**Date**: 2026-07-17  
**Branch**: `i485_i486_i487_esoz_employee_party_uat`

## Delivered (T020–T024)

| Gap | Fix |
|-----|-----|
| EMP-FILTERS | `filter.tax_id` + `filter.verification_status` on EmployeeIndex; display on party card |
| REQ-ID | ID/uuid column on EmployeeRequestIndex |
| OFFICIO-ONE | Medical types must have exactly one `speciality_officio=true` |
| OWNER-FALLBACK | Policies + UI elevate OWNER / PHARMACY_OWNER with ADMIN/HR |
| STATUS-MODEL | `isLocalDraft()` / `isPendingEhealth()`; edit/delete only drafts; SIGNED label «Надіслано»; create already keeps NEW |

## Remaining (Low)

- FR-GAP-323-DOC-SERIES — PASSPORT series UX polish

## Tests

- `EmployeePrimarySpecialityValidationTest`
- `EmployeeRequestPendingStatusTest`
- `EmployeeRequestStatusLabelTest`
- `EmployeeIndexAdminActionsTest`
