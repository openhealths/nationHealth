# Quality Checklist: ESОЗ Compliance Spec

**Feature**: 001-esoz-compliance-employee-party-contracts  
**Date**: 2026-07-16

## Completeness vs PDF

- [x] 3.1.1.4 covered (F-311-4)
- [x] 3.1.5 rem.1–6 covered (F-315-*)
- [x] 3.23 rem.1–15 mapped in inventory
- [x] 3.23.3.2.2 warning texts covered (#480)
- [x] Remaining gaps explicitly listed (death codes, NEW label, rebrand, PIB search)
- [x] #481 excluded with rationale (#407)

## Clarity

- [x] User stories have Given/When/Then
- [x] finding_id traceability in inventory
- [x] Combined branch merge order documented
- [x] Conflict policy for party_not_verified documented

## Consistency

- [x] Spec Done ↔ inventory DONE
- [x] Spec Gaps ↔ tasks Phase 3–6
- [x] UAT script referenced
- [ ] UAT script PR table updated to drop #481 / point C.17 to #480 (T050)

## Testability

- [x] Each Done FR maps to UAT section
- [x] Each Gap FR maps to task + UAT id
- [x] PHPUnit mentioned for gaps
