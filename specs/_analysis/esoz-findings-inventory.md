# ESОЗ findings inventory (analysis)

**Date**: 2026-07-16  
**Sources**: `storage/app/esoz-findings/pdf-extracts.txt`, PRs #462/#474/#476/#480, aggregate notes  
**Combined UAT branch**: `testing/esoz-combined-uat`  
**Excluded**: #481 (optional hardening; live bug fixed by #407)

| finding_id | pdf_section | expected | status | evidence | acceptance |
|---|---|---|---|---|---|
| F-311-4 | 3.1.1.4 | Full 403 party_not_verified message | DONE | #476 → `EHealthResponseException`, `errors.php` | UAT B.1 |
| F-315-1 | 3.1.5 rem.1 | id_form / contract type on details | DONE | #462 ContractShow / basic-data | UAT D.1 |
| F-315-2 | 3.1.5 rem.2 | inserted_at not “today” | DONE | #462 | UAT D.2 |
| F-315-3 | 3.1.5 rem.3 | FORWARD/BACKWARD UK | DONE | #462 nhs-customer + contracts.php | UAT D.3 |
| F-315-4 | 3.1.5 rem.4 | Period on contracts index | DONE | #462 contract-index | UAT D.4 |
| F-315-5 | 3.1.5 rem.5 | Капітація label for PMD | DONE | #462 contract-request-index | UAT D.5 |
| F-315-6 | 3.1.5 rem.6 | OWNER PMD cannot approve reimbursement | DONE | #462 ContractRequestPolicy | UAT D.6 |
| F-323-1 | rem.1 form.documents | Localized birth-doc errors | PARTIAL | #474 validation custom | UAT C.1 |
| F-323-2 | rem.2 / 1.2.1 | NEW → UI «Новий» | GAP | badge uses «Чернетка» | UAT C.2 |
| F-323-3 | rem.3 | PERMANENT_RESIDENCE_PERMIT + no_tax_id | DONE | #474 AbstractEmployeeFormManager | UAT C.3 |
| F-323-4 | rem.4 | Співробітник → Працівник | GAP | forms.php still mixed | UAT C.4 |
| F-323-5 | rem.5 | Lock tax_id | DONE | #474 party.blade + mapRevisionData | UAT C.5 |
| F-323-6 | rem.6 | Custom position allowed types | DONE | #474 EmployeeForm | UAT C.6 |
| F-323-7 | rem.7 | Dual speciality_officio UK | DONE | #474 EHealthValidationException | UAT C.7 |
| F-323-8 | rem.8 | ADMIN/HR edit | DONE | #474 EmployeePolicy | UAT C.8 |
| F-323-9 | rem.9 | ADMIN list access | DONE | #474 EmployeePolicy viewAny | UAT C.9 |
| F-323-10 | rem.10 | Party verification no 500 | DONE | #474 PartyVerificationIndex try/catch | UAT C.10 |
| F-323-11 | rem.11 / 3.4 | MANUAL_CONFIRMED / NOT_CONFIRMED | GAP | still MANUAL_DECEASED on combined | UAT C.11 |
| F-323-12 | rem.12–13 | Owner deactivate STOPPED/EIE | PARTIAL | #474 + 01e8525 | UAT C.12–13 |
| F-323-14 | rem.14 | Деактивувати | DONE | #474 forms.php | UAT C.14 |
| F-323-15 | rem.15 | end_date field | DONE | #474 deactivate-modal | UAT C.15 |
| F-323-rec | recommendation | Tokenized PIB search | GAP | CONCAT ILIKE on #474 | UAT C.16 |
| F-323-322 | 3.23.3.2.2 | Warning copy + dms_passport | DONE | #480 on combined | UAT C.17 |
| F-323-341 | 3.4.1 | verification_comment possible | DONE | #474 PartyVerify | UAT C.11 related |
| F-432 | roles sync | mapMany null-safe | EXCLUDED | #481 out of package (#407 fixed create 500) | — |
