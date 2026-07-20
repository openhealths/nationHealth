# Report: Critical / High fixes — ESОЗ 3.23 (PR #488)

**Date**: 2026-07-17  
**Branch**: `i485_i486_i487_esoz_employee_party_uat`  
**Spec**: `specs/002-esoz-3-23-employee-records`

## Summary

Closed all **Critical** and **High** gaps from the 3.23 Spec Kit inventory (T010–T014). Medium gaps remain open.

## Fixes delivered

| ID | Severity | Change |
|----|----------|--------|
| FR-GAP-323-LOCK-UI | Critical | `position.blade.php` disables type / position / start_date when `isCorePositionDataLocked` |
| FR-GAP-323-DIVISION-LOCK | High | `EmployeeEdit` sets `isPositionDataLocked=false`; division + medical blocks stay editable; backend still rewrites immutable fields |
| FR-GAP-323-INVITE-MSG | High | `employees.sign_success` mentions automatic email invitation |
| FR-GAP-323-SHOW-MEDICAL | High | `employee-show` shows professional blocks for all `config('ehealth.medical_employees')` |
| FR-GAP-323-PREVIEW | High | Pre-KEP preview modal → then signature modal (`prepareForSigning` / `proceedToSigning`) |

## Key files

- `resources/views/livewire/employee/parts/position.blade.php`
- `app/Livewire/Employee/EmployeeEdit.php`
- `app/Livewire/Employee/AbstractEmployeeFormManager.php`
- `app/Livewire/Employee/EmployeeComponent.php`
- `resources/views/livewire/employee/parts/modals/request-preview-modal.blade.php`
- `resources/views/livewire/employee/{employee,employee-edit,employee-position-add,employee-show}.blade.php`
- `resources/views/livewire/party/party-edit.blade.php`
- `resources/lang/uk/employees.php`, `resources/lang/uk/forms.php`
- `tests/Unit/Livewire/Employee/EmployeeCriticalHighGapsTest.php`

## Still open (Medium)

- FR-GAP-323-EMP-FILTERS — tax_id / verification_status on employees list  
- FR-GAP-323-REQ-ID — request id column  
- FR-GAP-323-OFFICIO-ONE — exactly one primary speciality  
- FR-GAP-323-OWNER-FALLBACK — OWNER/PHARMACY_OWNER policy  
- FR-GAP-323-STATUS-MODEL — keep NEW after sign (#493)

## Manual UAT smoke

1. Edit APPROVED employee → type/position/start_date disabled; division editable.  
2. Create/update → «Завершити та підписати» → preview → KEP.  
3. After successful sign → flash mentions invitation email.  
4. Show SPECIALIST/ASSISTANT → education/specialities visible.
