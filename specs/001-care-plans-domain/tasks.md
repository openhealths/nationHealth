# Tasks: Care Plans Domain

**Input**: Design documents from `/specs/001-care-plans-domain/`  
**Prerequisites**: plan.md, spec.md, research.md, data-model.md, contracts/  
**Tests**: Обов’язкові Feature-тести (constitution IV)

## Format: `[ID] [P?] [Story] Description`

- **[P]**: можна паралельно
- **[Story]**: US1…US7

---

## Phase 1: Setup (Shared Infrastructure)

- [ ] T001 Зафіксувати Spec Kit артефакти в гілці `spec_care_plans_domain` (`.specify/`, `specs/001-care-plans-domain/`, cursor skills)
- [ ] T002 [P] Додати коротке посилання з `docs/` на `specs/001-care-plans-domain/spec.md` (індекс домену Care Plan)
- [ ] T003 Перевірити `vendor/bin/sail artisan config:clear` у CI/локальних інструкціях quickstart

---

## Phase 2: Foundational (Blocking)

- [ ] T004 Узгодити persistence критичних полів плану (`terms_of_service`, `effectivePeriod`, `inform_with`) у `CarePlanRepository` / create-sign paths
- [ ] T005 [P] Гарантувати виключення local-only `clinical_protocol` / `instantiates_protocol` з CBD payload
- [ ] T006 [P] Уніфікувати обробку eHealth jobs (create plan, approval) через існуючий job resolver
- [ ] T007 Заборонити передачу КЕП-секретів у query string (signature modal POST-only)

**Checkpoint**: Foundation ready — user stories можна імплементувати

---

## Phase 3: User Story 1 — Створити план (P1) 🎯 MVP

**Goal**: Лікар створює й підписує план у ЕСОЗ  
**Independent Test**: Create+sign на пацієнті з finished encounter

### Tests

- [ ] T010 [P] [US1] Feature: create+sign persisting terms_of_service і period — `tests/Feature/CarePlan/CarePlanLifecycleTest.php`
- [ ] T011 [P] [US1] Feature: блок create без діагнозів/encounter — той самий файл або окремий

### Implementation

- [ ] T012 [US1] Перевірити/виправити `CarePlanCreate` / `CarePlanUpdate` save+sign persistence
- [ ] T013 [US1] Синхронізація `effectivePeriod` після відповіді CBD
- [ ] T014 [US1] UI помилок Системи + retry без втрати форми
- [ ] T015 [US1] Відображення UUID/статусу в index/show/person care-plans

**Checkpoint**: US1 done

---

## Phase 4: User Story 2 — Approvals (P1)

**Goal**: Verified write approval на care_plan  
**Independent Test**: OTP verify → activity create succeeds

### Tests

- [ ] T020 [P] [US2] Feature: approval create job → uuid swap → verify — `tests/Feature/CarePlan/CarePlanApprovalsTest.php`
- [ ] T021 [P] [US2] Feature: resend SMS — `tests/Feature/CarePlan/ApprovalResendSmsTest.php`

### Implementation

- [ ] T022 [US2] `CarePlanApprovals`: create/poll/verify/resend/cancel UX
- [ ] T023 [US2] Обмежити granted_to DOCTOR/SPECIALIST
- [ ] T024 [US2] CTA на 403 Access denied у activity/referral/eRx flows
- [ ] T025 [US2] Implicit declaration access detection

**Checkpoint**: US2 done

---

## Phase 5: User Story 3 — Activities (P1)

**Goal**: Підписані assignments трьох kinds  
**Independent Test**: Sign activity кожного kind

### Tests

- [ ] T030 [P] [US3] Unit/Feature: `resolvedKind` + guard «not in eHealth» — існуючі тести розширити
- [ ] T031 [P] [US3] Feature: cancel/complete activity з reason dictionaries

### Implementation

- [ ] T032 [US3] Create/sign activity у `CarePlanShow` / lifecycle concern
- [ ] T033 [US3] `CarePlanActivityEHealthGuard` на всіх issue-document entry points
- [ ] T034 [US3] Ліміти quantity/program перед випискою

**Checkpoint**: US3 done

---

## Phase 6: User Story 4 — ЕН Service/Device (P2)

**Goal**: Prequalify → sign referral  
**Independent Test**: Service + device E2E з activity

### Tests

- [ ] T040 [P] [US4] Feature: referral createDraft prequalify path
- [ ] T041 [P] [US4] Feature: device prequalify only for device (не activity sign)

### Implementation

- [ ] T042 [US4] `ReferralRequestLifecycleService` + mappers based_on care_plan+activity
- [ ] T043 [US4] Catalog filters `request_allowed` / program participation
- [ ] T044 [US4] paper_referral обов’язкові поля для СМД context (якщо UI encounter)
- [ ] T045 [US4] Пошук ЕМЗ погашення ЕН для автора (read path)

**Checkpoint**: US4 done

---

## Phase 7: User Story 5 — e-рецепт (P2)

**Goal**: Prequalify + create + sign + reject  
**Independent Test**: MR ACTIVE; reject MRR і MR

### Tests

- [ ] T050 [P] [US5] Feature: prequalify→create→sign MRR
- [ ] T051 [P] [US5] Feature: reject MRR (NEW) і reject MR (ACTIVE)

### Implementation

- [ ] T052 [US5] Розширити `MedicationRequestLifecycleService` (паритет referral)
- [ ] T053 [US5] API client: prequalify + reject endpoints (прибрати бізнес-залежність від cancel)
- [ ] T054 [US5] Mapper: snake_case create payload, SNOMED route, based_on, periods, max_dose_*
- [ ] T055 [US5] Persistence `inform_with`; resend OTP; printout

**Checkpoint**: US5 done

---

## Phase 8: User Story 6 — Complete/Cancel plan (P2)

**Goal**: Complete без КЕП (з preconditions activities); cancel за Cancel API  
**Independent Test**: Complete з ≥1 completed activity; 409 якщо є scheduled/in-progress

### Tests

- [ ] T060 [P] [US6] Feature: complete без DS + activity preconditions
- [ ] T060b [P] [US6] Feature: cancel path per Cancel Care Plan API

### Implementation

- [ ] T061 [US6] Complete: прибрати вимогу КЕП; слати `status_reason`; перевірити activities перед викликом
- [ ] T062 [US6] Cancel: узгодити з Cancel Care Plan API (DS якщо вимагається)
- [ ] T063 [US6] Блок нових activities після terminal status

**Checkpoint**: US6 done

---

## Phase 9: User Story 7 — Search/Sync (P3)

**Goal**: Sync і пошук планів пацієнта  
**Independent Test**: Sync оновлює список; 403→consent message

### Tests

- [ ] T070 [P] [US7] Feature: CarePlan sync / index filters

### Implementation

- [ ] T071 [US7] `CarePlanFullSync` / `PatientCarePlans` messaging
- [ ] T072 [US7] Пошук за UUID у UI
- [ ] T073 [US7] Merged persons — лише якщо доступ уже реалізований у person search (wire if present)

**Checkpoint**: US7 done

---

## Phase 10: Polish & Cross-cutting

- [ ] T080 [P] Pint dirty PHP files
- [ ] T081 Оновити `docs/ehealth_approvals_architecture.md` і phase-0 якщо контракти змінені
- [ ] T082 `/speckit-analyze` consistency pass; виправити розбіжності spec↔plan↔tasks
- [ ] T083 UAT quickstart прогін + Discord `completed` при milestone

---

## Dependencies (story order)

```text
Phase1 → Phase2 → US1 → US2 → US3 → (US4 ∥ US5) → US6 → US7 → Polish
```

US4 і US5 можуть йти паралельно після US3.  
US6 залежить від US1 persistence fixes (T004/T013).

## Parallel examples

- T010 ∥ T011  
- T020 ∥ T021  
- T040 ∥ T041  
- T050 ∥ T051  
- T042–T045 після T040 контрактів  
- T052–T055 після T050
