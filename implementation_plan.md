# Implementation Plan - Care Plan Clinical Protocol & Field Persistence Fixes

This plan addresses a schema validation error that occurs when creating and signing a Care Plan, caused by the Central Database (CBD) API not accepting the `instantiates_protocol` parameter. We will also correct local database persistence issues where several fields (like `terms_of_service`, `clinical_protocol`, `context`, etc.) were left out of local DB updates upon Care Plan creation and signing.

## User Review Required

> [!IMPORTANT]
> **Clinical Protocol as a Local-Only Field:**
> In the Ukrainian eHealth Central Database (CBD) API, there is no dictionary of clinical protocols, and the `CarePlan` resource JSON schema has no field for clinical protocols (no `instantiates_protocol` or standard FHIR `instantiatesProtocol` / `instantiatesCanonical` / `instantiatesUri` is accepted by their central database validation schemas).
>
> Therefore, **"Clinical Protocol" (клінічний протокол) is a local-only field**. We will keep the field in the creation/editing forms and persist it in the local database (`clinical_protocol` column in `care_plans` table), but we will completely exclude it from the request payload sent to the eHealth CBD API. This will resolve the "schema is not correct, due to additional parameter" error returned by eHealth when signing/registering the Care Plan.

## Proposed Changes

---

### 1. API Payload Formatting

#### [MODIFY] [CarePlanRepository.php](file:///wsl.localhost/Ubuntu/home/mefizz/projects/ohealth/app/Repositories/CarePlanRepository.php)
- Remove `instantiates_protocol` from the eHealth request payload array returned by `formatCarePlanRequest()`.
- Add `'inform_with' => $form['informWith'] ?? ($form['inform_with'] ?? null)` to the payload, as `inform_with` is a valid eHealth CBD schema parameter representing the patient's selected notification channel (OTP, SMS, etc.).

#### [MODIFY] [CarePlanShow.php](file:///wsl.localhost/Ubuntu/home/mefizz/projects/ohealth/app/Livewire/CarePlan/CarePlanShow.php)
- Remove `instantiates_protocol` from the cancel/complete payload array (`$payload` on line 697).
- Remove `instantiates_protocol` from the signing payload array (`$carePlanPayload` on line 821).

---

### 2. Local Database Persistence

#### [MODIFY] [CarePlanCreate.php](file:///wsl.localhost/Ubuntu/home/mefizz/projects/ohealth/app/Livewire/CarePlan/CarePlanCreate.php)
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
