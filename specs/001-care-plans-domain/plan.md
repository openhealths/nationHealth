# Implementation Plan: Плани лікування та пов’язані сутності

**Branch**: `spec_care_plans_domain` | **Date**: 2026-07-16 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `/specs/001-care-plans-domain/spec.md`

## Summary

Закрити compliance-прогалини домену Care Plan у brownfield МІС: життєвий цикл плану й activities з КЕП і синхронізацією signed content; Approvals (consent); електронні направлення (Service/Device Request) і електронні рецепти (MRR/MR) з prequalify та reject-контрактами ЕСОЗ. База — існуючі Livewire-компоненти й API-клієнти; ціль — уніфіковані lifecycle-сервіси та тести без руйнування `mis_dev`.

## Technical Context

**Language/Version**: PHP 8.5  
**Primary Dependencies**: Laravel 12, Livewire 3, Fortify, Sail, existing `App\Classes\eHealth\*` clients  
**Storage**: PostgreSQL (`care_plans`, `care_plan_activities`, `approvals`, `*_request_requests`), Mongo medical events twin where already used  
**Testing**: PHPUnit 12 via `vendor/bin/sail artisan test` (`DB_DATABASE=testing`)  
**Target Platform**: Laravel Sail (Docker), browser UI для лікаря  
**Project Type**: Brownfield web MIS (server-rendered Livewire)  
**Performance Goals**: Job polling approvals/care-plan create < 30s typical; UI без блокування на довгих jobs (poll)  
**Constraints**: eHealth CBD schema strict; КЕП payload must match stored resource; no secrets in URL; scopes `care_plan:*`, `approval:*`, `medication_request*`  
**Scale/Scope**: 1 domain feature set (~7 user stories); reuse existing UI routes under dashboard legal entity

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

| Principle | Status | Notes |
|-----------|--------|-------|
| I. eHealth Compliance First | PASS | Contracts align to CBD; gaps documented in research |
| II. Consent Before Clinical Write | PASS | Approvals + declaration implicit access in FR-020–024 |
| III. Sign What You Persist | PASS | Period/terms sync called out; cancel/complete risk mitigated |
| IV. Test-Proven Changes | PASS | tasks.md includes Feature tests per story |
| V. Brownfield Clarity & Minimal Diff | PASS | Extend lifecycle services; avoid new top-level packages |

**Post-design**: PASS — data-model and contracts map to existing tables/API classes.

## Project Structure

### Documentation (this feature)

```text
specs/001-care-plans-domain/
├── plan.md
├── research.md
├── data-model.md
├── quickstart.md
├── contracts/
│   ├── care-plan.md
│   ├── approvals.md
│   ├── service-device-requests.md
│   └── medication-requests.md
├── checklists/requirements.md
├── tasks.md
└── spec.md
```

### Source Code (repository root)

```text
app/
├── Classes/eHealth/Api/
│   ├── CarePlan.php
│   ├── CarePlanActivity.php
│   ├── Approval.php
│   └── Patient/{MedicationRequest,ServiceRequest,DeviceRequest}.php
├── Livewire/CarePlan/
│   ├── CarePlanCreate.php / CarePlanUpdate.php / CarePlanShow.php
│   ├── CarePlanApprovals.php
│   ├── Activity/Show/CarePlanActivityShow.php
│   └── Concerns/{ManagesCarePlanLifecycle,ManagesCarePlanEPrescription,ManagesCarePlanReferrals}.php
├── Services/…/{ReferralRequestLifecycleService,MedicationRequestLifecycleService,…}
├── Repositories/{CarePlanRepository,CarePlanActivityRepository}.php
├── Models/… CarePlan, CarePlanActivity, Approval, *Request*
└── Policies/CarePlanPolicy.php

resources/views/livewire/care-plan/
tests/Feature/CarePlan/
docs/{ehealth_approvals_architecture,eprescription-referrals/phase-0-spec,care_plan_fixes_plan}.md
```

**Structure Decision**: Залишаємось у існуючому Laravel-модулі Care Plan; нові файли — сервіси/тести/мапери за потреби, без нової директорії верхнього рівня.

## Complexity Tracking

| Violation | Why Needed | Simpler Alternative Rejected Because |
|-----------|------------|-------------------------------------|
| Dual persistence SQL + Mongo CarePlan twin | Історична модель medical events | Повна міграція на SQL-only — поза scope |
| Livewire + Service layer overlap | Поступовий винос lifecycle з компонент | Big-bang rewrite UI — занадто ризиковано для compliance hotfix |
