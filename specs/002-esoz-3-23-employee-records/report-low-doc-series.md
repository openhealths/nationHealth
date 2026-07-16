# Report: Low DOC-SERIES — ESОЗ 3.23 (PR #488)

**Date**: 2026-07-17  
**Branch**: `i485_i486_i487_esoz_employee_party_uat`

## FR-GAP-323-DOC-SERIES (3.23.1.3.2)

For PASSPORT / REFUGEE_CERTIFICATE / COMPLEMENTARY_PROTECTION_CERTIFICATE:

- UI collects **series** (2 Cyrillic letters) + **number** (digits) separately
- Table / preview show combined value
- eHealth payload still uses a single `number` (`АА123456`)
- Other types keep a single number field

Helper: `App\Support\EmployeeDocumentSeriesNumber`  
UI: `resources/views/livewire/employee/parts/documents.blade.php`  
Normalize: `EmployeeForm::getPreparedData()` + hydrate split

Also: delete-draft confirmation mentions status «Новий».

## Tests

- `EmployeeDocumentSeriesNumberTest`
- `EmployeeFormPreparedDataTest` (combine series+number)
- existing `DocumentNumberTest`
