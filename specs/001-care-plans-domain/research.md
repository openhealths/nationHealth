# Research: Care Plans Domain

**Date**: 2026-07-16  
**Feature**: `001-care-plans-domain`

## R1. Що дає вставлене ТЗ (3.2–3.3) для Care Plans

**Decision**: Трактувати 3.2/3.3 як **передумови та суміжні контракти**, не як повний текст вимог до Care Plan.

**Rationale**: Текст покриває декларації, ЕМЗ (епізоди/пакети взаємодії), ЕН, approvals на write для encounter package, каталог послуг. План лікування як окремий ресурс у цьому фрагменті не розписаний, але ЕН/approvals/ЕМЗ жорстко блокують care-plan flows.

**Alternatives**: Ігнорувати 3.2/3.3 — відхилено (втратимо preconditions). Чекати окремий розділ ТЗ — відхилено як блокер; фіксуємо цільову поведінку зараз.

## R2. Статусна модель плану і activities

**Decision**: Дотримуватися eHealth Care Plan statuses; локальний enum `CarePlanStatus` мапить `new`↔PENDING, `active`↔ACTIVE тощо. Activities: draft → signed (`scheduled`/`in-progress`) → cancel/complete.

**Rationale**: Відповідає поточному коду й CBD.

**Alternatives**: Власна статусна машина МІС — відхилено (роз’їзд із Системою).

## R3. Approvals vs Declaration

**Decision**: Implicit access через активну декларацію; інакше explicit Approval на `care_plan` з verify. Write approval обов’язковий для non-author write в тому ж LE (узгоджено з логікою 3.2.2.8 / 3.3.1.11).

**Rationale**: Документ `docs/ehealth_approvals_architecture.md` + поведінка CBD (`403`).

## R4. Medication: Reject vs Cancel

**Decision**: Цільовий контракт — Reject MRR / Reject MR з довідником причин (Phase-0 spec). Поточний `actions/cancel` вважати technical debt до закриття.

**Rationale**: Confluence/Apiary medication status model; Phase-0 вже зафіксував gap.

**Alternatives**: Залишити cancel — відхилено (non-compliance).

## R5. based_on shape

**Decision**: `based_on` = два identifier: `care_plan` + `activity` (не `care_plan_activity`).

**Rationale**: Phase-0 + Service Requests data model.

## R6. Prequalify

**Decision**: Обов’язковий для medication `intent=order` і для device (та service з програмою за існуючим `ReferralRequestLifecycleService`).

**Rationale**: API-005-044-0001 / CR-207; існуючий referral lifecycle.

## R7. Signed content sync

**Decision**: Після create/sign плану синхронізувати `effectivePeriod` і `terms_of_service` локально; cancel/complete будувати з актуального знімка (див. `docs/care_plan_fixes_plan.md`).

**Rationale**: Відомі прод-помилки mismatch / missing code.

## R8. Lifecycle services

**Decision**: Розширити `MedicationRequestLifecycleService` до паритету з `ReferralRequestLifecycleService` (prequalify → draft → sign → reject/sync); поступово тоншати Livewire traits.

**Rationale**: Constitution V — minimal diff, clear seams for tests.

## R9. UI / security

**Decision**: Cancel/complete лише через POST/Livewire actions + signature modal; ніколи GET з паролем КЕП.

**Rationale**: care_plan_fixes_plan security finding.

## Open items (non-blocking)

- Точний patient-scoped vs global path для Create MRR — перевірити Apiary на UAT; обидва варіанти задокументувати в contracts.
- Окремий офіційний розділ ТЗ «Плани лікування» — додати через clarify, коли нададуть текст.
