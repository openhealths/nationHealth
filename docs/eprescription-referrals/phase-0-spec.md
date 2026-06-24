# ePrescription & Electronic Referrals — Phase 0 Specification

> Issue: [#365](https://github.com/openhealths/nationHealth/issues/365)  
> Branch: `i365_eprescription_referrals`  
> Scope: Medication Request (e-рецепт) + Service/Device Request (електронні направлення)  
> Out of scope: CarePlan CRUD, activity lifecycle (cancel/complete plan), device activity payload tuning

## References

| Topic | Link |
|-------|------|
| Service Request data model | [AH Service Requests Data Model v1](https://e-health-ua.atlassian.net/wiki/spaces/EH/pages/18213699635/AH+Service+Requests+Data+Model+v1+04.09.24) |
| Create MRR | [API-005-044-0002](https://e-health-ua.atlassian.net/wiki/spaces/ESOZ/pages/17570693379/REST+API+Create+Medication+Request+Request+API-005-044-0002) |
| PreQualify MRR | [API-005-044-0001](https://e-health-ua.atlassian.net/wiki/spaces/ESOZ/pages/17570660609/REST+API+PreQualify+Medication+Request+Request+API-005-044-0001) |
| PreQualify logic (CR-207) | [PreQualify Medication request](https://e-health-ua.atlassian.net/wiki/spaces/ESOZ/pages/19674497506/PreQualify+Medication+request_EN+CR-207) |
| Sign MRR | [API-005-044-0006](https://e-health-ua.atlassian.net/wiki/spaces/ESOZ/pages/17570824338/REST+API+Sign+Medication+Request+Request+API-005-044-0006) |
| Reject MR (ACTIVE) | [API-005-043-0006](https://e-health-ua.atlassian.net/wiki/spaces/ESOZ/pages/17570758873/REST+API+Reject+Medication+Request+API-005-043-0006) |
| Reject MRR (NEW) | [API-005-044-0007](https://e-health-ua.atlassian.net/wiki/spaces/ESOZ/pages/17570529463) |
| Status model | [Medication request status model](https://e-health-ua.atlassian.net/wiki/spaces/ESOZ/pages/18523357283/Medication+request+status+model) |
| Dummy Sign (sandbox) | [Apiary — Sign MRR](https://uaehealthapi.docs.apiary.io/#reference/dummy-methods/dummy-for-medication-request-requests/sign-medication-request-requests) |
| Dummy Reject MR | [Apiary — Reject MR](https://uaehealthapi.docs.apiary.io/#reference/dummy-methods/dummy-for-medication-request/reject-medication-request) |

---

## 1. Status model (eHealth)

### Medication Request **Request** (MRR) — заявка до підпису

| Status | Transition | Method |
|--------|------------|--------|
| `NEW` | on create | Create MRR |
| `SIGNED` | from `NEW` | Sign MRR (КЕП) → створює MR |
| `REJECTED` | from `NEW` | Reject MRR |
| `EXPIRED` | from `NEW` | auto |

### Medication Request (MR) — підписаний рецепт

| Status | Transition | Method |
|--------|------------|--------|
| `ACTIVE` | on Sign MRR | Sign |
| `COMPLETED` | from `ACTIVE` | dispense complete |
| `REJECTED` | from `ACTIVE` | Reject MR + `reject_reason_code` |
| `EXPIRED` | from `ACTIVE` | auto |

**Висновок для MIS:** після Sign MRR локально зберігаємо MR зі статусом `ACTIVE`. Скасування активного рецепта — це **reject**, не `cancel` + `status_reason`.

---

## 2. Create Medication Request Request (API-005-044-0002)

### Endpoint (patient-scoped у MIS)

`POST /api/patients/{person_id}/medication_request_requests`

Scope: `medication_request_request:write`

### Формат тіла (Confluence / Apiary)

Плоский **snake_case** об'єкт (не FHIR camelCase):

```json
{
  "medication_request_request": {
    "person_id": "uuid",
    "employee_id": "uuid",
    "division_id": "uuid",
    "created_at": "2017-08-17",
    "started_at": "2017-08-17",
    "ended_at": "2017-09-16",
    "medication_id": "innm_dosage_uuid",
    "medication_qty": 10.34,
    "medical_program_id": "uuid",
    "intent": "order",
    "category": "community",
    "based_on": [
      { "identifier": { "type": { "coding": [{ "system": "eHealth/resources", "code": "care_plan" }] }, "value": "care_plan_uuid" } },
      { "identifier": { "type": { "coding": [{ "system": "eHealth/resources", "code": "activity" }] }, "value": "activity_uuid" } }
    ],
    "context": { "identifier": { "type": { "coding": [{ "system": "eHealth/resources", "code": "encounter" }] }, "value": "encounter_uuid" } },
    "dosage_instruction": [ "..." ],
    "priority": "routine",
    "container_dosage": { "system": "MEDICATION_UNIT", "code": "ML", "value": 4 }
  }
}
```

### Ключові правила валідації

- `intent`: `order` (для виписки з погашенням) або `plan` (без dispense; prequalify не застосовується)
- `started_at` / `ended_at`: термін лікування; `ended_at >= started_at >= created_at`
- `dispense_valid_from` = created_at; `dispense_valid_to` — з програми або глобального параметра
- `medication_id`: активний INNM_DOSAGE
- **`based_on`**: масив з **двох** посилань — `care_plan` + `activity` (не один `care_plan_activity`)
- Activity: `kind=medication_request`, `product_reference=medication_id`, status `scheduled` | `in_progress`
- Залишок qty: логіка `remaining_quantity_type` (`for_request` / `for_use`)
- `medical_program_id` має збігатися з program activity
- **dosage_instruction.route**: `eHealth/SNOMED/route_codes` (код SNOMED, не рядок `oral`)
- **max_dose_per_period**: структура `{ numerator, denominator }` з ucum units
- **max_dose_per_administration**: `{ value, unit, system, code }`
- Програма: `skip_treatment_period`, `skip_mnn_in_treatment_period`, `request_max_period_day`

### Розбіжність з поточним кодом (PR #355)

| Документація | Поточна реалізація | Пріоритет |
|--------------|-------------------|-----------|
| snake_case `medication_request_request` wrapper | FHIR camelCase через `MedicationRequestMapper` | P0 — перевірити Apiary patient endpoint |
| `based_on`: care_plan + activity | один `care_plan_activity` | P0 |
| `dosage_instruction` + SNOMED route | `route: oral` + `eHealth/vaccination_routes` | P0 |
| `started_at`/`ended_at` у create body | є в DB, **не в FHIR mapper** | P0 |
| `max_dose_per_*` у dosage | зберігається в DB, **не в mapper** | P0 |
| PreQualify перед create | **відсутній** для medication | P0 |
| Reject MR / Reject MRR | `cancel` + `status_reason` + `care_plan_cancel_reasons` | P0 |
| `inform_with` persistence | поле є в model/migration, **repository не пише** | P1 |

---

## 3. PreQualify (API-005-044-0001 + CR-207)

- Scope: `medication_request_request:write`
- Тільки `intent = order` (plan → 409 "Plan can't be qualified")
- Викликати **до** Create для програм з reimbursement
- Перевірки: INNM у програмі, відсутність перетину періодів, care_plan_required, діагноз encounter, employee, activity, period, context, person
- Відповідь: масив програм з `VALID` / `INVALID` + `rejection_reason`

**Ціль Phase 1:** `MedicationRequestLifecycleService::createDraft()` — prequalify → create → persist (аналог `ReferralRequestLifecycleService`).

---

## 4. Sign MRR (API-005-044-0006)

- `PATCH .../medication_request_requests/{id}/actions/sign`
- Body: `{ signed_data, signed_data_encoding: "base64" }`
- Підписується контент MRR (snake_case після decode)
- Результат: MRR → `SIGNED`, створюється MR → `ACTIVE`
- Може повертати job link → polling через `EHealthJobResolver`

**Dummy sandbox:** Apiary dummy sign — для UAT без реального КЕП на dev (якщо увімкнено).

---

## 5. Reject (не Cancel)

### Reject MRR (статус NEW) — API-005-044-0007

- Scope: `medication_request_request:reject`
- Перехід: `NEW` → `REJECTED`

### Reject MR (статус ACTIVE) — API-005-043-0006

- Scope: `medication_request:reject`
- Перехід: `ACTIVE` → `REJECTED`
- Обов'язково: `reject_reason_code` з словника **`MEDICATION_REQUEST_REJECT_REASON`**
- Опційно: `reject_reason` (текст)
- Підпис КЕП контенту reject

**Поточний код:** `MedicationRequest::cancel()` → `/medication_requests/{id}/actions/cancel` — **не відповідає** документації reject.

---

## 6. Service / Device referrals (коротко)

Вже ближче до специфікації в `ReferralRequestLifecycleService`:

- Prequalify → create → sign
- `ServiceRequestMapper::toPrequalifyPayload` — `based_on`: care_plan + activity
- Job resolver на create

Перевірити проти [Service Requests Data Model](https://e-health-ua.atlassian.net/wiki/spaces/EH/pages/18213699635/AH+Service+Requests+Data+Model+v1+04.09.24) у Phase 1.

---

## 7. Цільова архітектура (затверджено Phase 0)

```
CarePlanActivityShow (UI)
    ↓
ManagesCarePlanEPrescription / ManagesCarePlanReferrals (thin)
    ↓
MedicationRequestLifecycleService / ReferralRequestLifecycleService
    ↓
Mappers (toApiPayload / toSignPayload / toPrequalifyPayload)
    ↓
EHealth API clients + EHealthJobResolver
    ↓
Repositories (local persistence)
```

### Контракти сервісів (Phase 1+)

**MedicationRequestLifecycleService** (розширити):

- `prequalify(CarePlan, formData, employeeContext): PrequalifyResult`
- `createDraft(...): string` (uuid MRR)
- `sign(personUuid, mrrUuid, signature): SignedMedicationRequest`
- `rejectRequest(mrrUuid, ...)` / `rejectActive(mrUuid, rejectReasonCode, ...)`
- `resendSms`, `fetchPrintout`, `buildFallbackPrintout`
- `sumIssuedQuantity(activity): float`

---

## 8. План фаз

| Phase | Deliverable | Status |
|-------|-------------|--------|
| **0** | Цей документ + issue + branch | 🔄 in progress |
| **1** | Blockers: inform_with, mapper payload, based_on, reject API, prequalify hook | pending |
| **2** | Lifecycle refactor, EHealthJobResolver everywhere | pending |
| **3** | UX, UAT checklist, localized statuses | pending |
| **4** | Tests: create/sign/reject/prequalify Livewire + mapper | pending |

---

## 9. UAT (ручне, без тестового КЕП)

Користувач підписує власним КЕП. Рекомендовані дані:

- Patient id `3`, plan `38` (є encounter)
- Activity `14` — service referral, `15` — medication

Checklist — див. issue body.

---

## 10. Відкриті питання (потрібне уточнення з Apiary)

1. Чи patient-scoped endpoint приймає той самий `medication_request_request` wrapper, що й IL endpoint?
2. Точна назва поля автентифікації: `inform_with` vs `authorize_with` у create body?
3. Чи sign підписує повний MRR object чи окремий content hash?

*Оновлювати після першого успішного create/sign на sandbox.*
