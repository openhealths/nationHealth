# Tasks: ESОЗ Compliance — Employee, Party, Contracts

**Input**: `./plan.md`, `./spec.md`, `../_analysis/esoz-findings-inventory.md`  
**Tests**: Required for Remaining gaps (Feature PHPUnit). Baseline PRs already tested.

## Phase 1: Setup (combined UAT)

- [x] T001 Create `testing/esoz-combined-uat` from `fork/main`
- [x] T002 Merge #474 `#480` `#476` `#462` (exclude #481); resolve conflicts per plan
- [x] T003 Author Spec Kit artifacts under `specs/001-…` and `.specify/`
- [ ] T004 Push `testing/esoz-combined-uat` to `origin` (Mefizz/ohealth)
- [ ] T005 [P] Smoke: `vendor/bin/sail artisan test --compact` filters Employee/Party/Contract/EHealth party_not_verified

## Phase 2: Foundational — Manual UAT on combined

- [ ] T006 Execute `storage/app/esoz-findings/uat-script-esoz-loop2.md` on combined branch (update C.17 → #480; ignore #481)
- [ ] T007 Record PASS/FAIL/BLOCKED table; file OPEN GAPS only for FR-GAP-*

**Checkpoint**: Baseline DONE findings validated or infra-blocked.

## Phase 3: User Story Remaining — Death codes (P1) `[US-GAP1]`

**Goal**: FR-GAP-001 MANUAL_CONFIRMED / MANUAL_NOT_CONFIRMED  
**Independent Test**: PartyVerify payload + UAT C.11

- [ ] T010 [P] [US-GAP1] Create issue from `storage/app/esoz-findings/issue-death-reason-codes.md`
- [ ] T011 [US-GAP1] Update `resources/views/livewire/party/party-verify.blade.php` option values
- [ ] T012 [P] [US-GAP1] Update `resources/lang/uk/party_verification.php` reasons («працівник»)
- [ ] T013 [US-GAP1] Tighten `app/Livewire/Party/PartyVerify.php` reason allowlist + required comment
- [ ] T014 [US-GAP1] Fix `tests/Feature/Party/PartyVerificationTest.php` expectations
- [ ] T015 [US-GAP1] Draft PR; merge into combined UAT branch

## Phase 4: NEW label «Новий» (P2) `[US-GAP2]`

- [ ] T020 [US-GAP2] Map NEW badge/filter to `forms.status.new` («Новий») in employee index/views
- [ ] T021 [P] [US-GAP2] Feature/assert or UAT C.2 evidence
- [ ] T022 [US-GAP2] Draft PR → combined

## Phase 5: Rebrand Працівник (P2) `[US-GAP3]`

- [ ] T030 [P] [US-GAP3] Replace remaining Employee UI «Співробітник*» in `resources/lang/uk/forms.php` (+ hardcoded flash in EmployeeIndex if present)
- [ ] T031 [US-GAP3] Grep audit for Employee module leftovers
- [ ] T032 [US-GAP3] Draft PR → combined

## Phase 6: Tokenized PIB search (P2) `[US-GAP4]`

- [ ] T040 [US-GAP4] Restore order-independent token search in `app/Livewire/Employee/EmployeeIndex.php` (as in i431)
- [ ] T041 [P] [US-GAP4] Add/adjust Feature test for token order
- [ ] T042 [US-GAP4] Draft PR → combined

## Phase 7: Polish

- [ ] T050 [P] Refresh UAT script PR references (#480, drop #481)
- [ ] T051 Run `/speckit.converge`-style review: inventory vs combined branch
- [ ] T052 Hand off to QA with readiness table

## Dependencies

- Phase 2 UAT can start immediately after T004.
- Phases 3–6 are independent after Phase 2; prefer GAP1 first (certification blocker).

## Parallel opportunities

- T012/T014 after T011; T030 parallel with T040; T005 parallel with T006 if Sail up.
