# Analyze: Spec ↔ Plan ↔ Tasks ↔ Code (3.23)

**Date**: 2026-07-17  
**Feature**: `002-esoz-3-23-employee-records`

## Coverage matrix

| TZ block | Spec FR | Task | Code status |
|----------|---------|------|-------------|
| 3.23.1.1 roles create | FR-323-CREATE / OWNER-FALLBACK | T023 | PARTIAL |
| 3.23.1.2.1 status NEW | FR-323-STATUS-NEW / STATUS-MODEL | T024 | DONE + follow-up #493 |
| 3.23.1.2.2–2.4 form fields | FR-323-CREATE-FORM | — | DONE / SHOW-MEDICAL PARTIAL |
| 3.23.1.3 validation | FR-323-IDENTITY + OFFICIO-ONE | T022 | PARTIAL |
| 3.23.1.4 preview | FR-GAP-323-PREVIEW | T014 | GAP |
| 3.23.1.5 KEP | FR-323-KEP | — | DONE |
| 3.23.1.6.1 invite msg | FR-GAP-323-INVITE-MSG | T012 | GAP |
| 3.23.1.6.2 resubmit | FR-323-RESUBMIT | — | DONE |
| 3.23.1.7 locks | FR-GAP-323-LOCK-UI / DIVISION-LOCK | T010–T011 | GAP |
| 3.23.2 lists | FR-GAP-323-REQ-ID | T021 | PARTIAL |
| 3.23.3.1 filters | FR-GAP-323-EMP-FILTERS | T020 | PARTIAL |
| 3.23.3.2 details | FR-GAP-323-SHOW-MEDICAL | T013 | PARTIAL |
| 3.23.3.2.2 warnings | FR-323-WARNINGS | — | DONE |
| 3.23.3.4 death | FR-323-DEATH | — | DONE |
| 3.23.4 deactivate | FR-323-DEACTIVATE | — | DONE |

## Inconsistencies

1. Older `specs/001-…` still lists FR-GAP death codes as MANUAL_DECEASED — **obsolete** on this branch (fixed to MANUAL_CONFIRMED). Prefer 002 as source of truth for 3.23.
2. UI «Новий» for SIGNED on #488 is a label hack; #493 is the model fix.

## Result

**PASS as systematization artifact.** Certification blocked until Phase 1 tasks (T010–T014) close or are waived.
