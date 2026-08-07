# Implementation Plan - Care Plan Clinical Protocol & Field Persistence Fixes

This plan addresses a schema validation error that occurs when creating and signing a Care Plan, caused by the Central Database (CBD) API not accepting the `instantiates_protocol` parameter. We will also correct local database persistence issues where several fields (like `terms_of_service`, `clinical_protocol`, `context`, etc.) were left out of local DB updates upon Care Plan creation and signing.

## User Review Required

- Оновлення існуючої специфікації `docs/eprescription-referrals/phase-0-spec.md`: чи потрібно перейменувати її на загальну (наприклад, `care-plan-referrals-spec.md`), чи просто розширити поточний файл? За замовчуванням я розширю поточний файл.
- Архітектурний підхід для `ReferralController`: Він буде працювати як API-ендпоінт для Livewire/Vue фронтенду, оскільки процес "Пошуку направлення" (за 16-значним номером) зазвичай ініціюється окремо від епізоду (через ABAC це дозволено виконавцю).

## Open Questions

- Чи потрібна генерація PDF/HTML для друкованої форми погашеного направлення в цьому етапі, чи ми фокусуємося лише на API-інтеграції?

## Proposed Changes

- In `save()` (draft creation), include the missing `'terms_of_service' => $this->form->termsOfService ?: null` in the `create` array.
- In `sign()` (after successful eHealth job processing), expand the `create` array to persist all form fields locally instead of just a subset:
  - `'clinical_protocol' => $this->form->clinicalProtocol ?: null`
  - `'context' => $this->form->context ?: null`
  - `'terms_of_service' => $this->form->termsOfService ?: null`
  - `'description' => $this->form->description ?: null`
  - `'note' => $this->form->note ?: null`
  - `'inform_with' => $this->form->informWith ?: null`
  - `'addresses' => $encounterData['addresses']`
  - `'supporting_info' => ['episodes' => $this->form->episodes, 'medical_records' => $this->form->medicalRecords]`

#### [MODIFY] [CarePlanUpdate.php](file:///wsl.localhost/Ubuntu/home/mefizz/projects/ohealth/app/Livewire/CarePlan/CarePlanUpdate.php)
- In `save()` (draft update), include the missing `'terms_of_service' => $this->form->termsOfService ?: null` in the update array.
- In `sign()` (after successful eHealth job processing), expand the update array to persist all edited fields:
  - `'clinical_protocol' => $this->form->clinicalProtocol ?: null`
  - `'context' => $this->form->context ?: null`
  - `'terms_of_service' => $this->form->termsOfService ?: null`
  - `'description' => $this->form->description ?: null`
  - `'note' => $this->form->note ?: null`
  - `'inform_with' => $this->form->informWith ?: null`

---

## Verification Plan

### Automated Tests
- Run Care Plan tests using:
  `wsl ./vendor/bin/sail artisan test --compact --filter=CarePlan`
- Add unit/feature tests in `tests/Feature/CarePlan/CarePlanLifecycleTest.php` to verify that when saving or signing a Care Plan, the `terms_of_service` and `clinical_protocol` values are correctly saved to the local database, and that `instantiates_protocol` is NOT present in the signed data payload.

### Manual Verification
- Verify the Care Plan creation form in the local web interface:
  1. Open the Care Plan creation page.
  2. Input a value for the "clinical protocol" field.
  3. Sign and send the Care Plan, ensuring that no schema validation error is returned by eHealth and the Care Plan is registered successfully.
  4. Verify that the clinical protocol and terms of service are correctly saved in the local database.
