# Nation Health — ESОЗ Compliance Constitution

## Core Principles

### PDF is source of truth
Acceptance criteria for certification findings MUST match ESОЗ tester conclusion PDFs literally (Ukrainian informational texts, status/reason codes). Do not “improve” official wording.

### Distinguish Done vs Remaining
Specifications MUST separate already-shipped PR work from open gaps. Do not rewrite working PR code without a finding_id.

### Testability
Every functional requirement MUST be verifiable by PHPUnit Feature tests and/or the manual UAT script (`storage/app/esoz-findings/uat-script-esoz-loop2.md`).

### Sail-only execution
Artisan, Composer, npm, Pint, and tests run via `vendor/bin/sail` (or project `make` targets). Never run host `php artisan` against the app.

### Fork-only feature branches
Feature branches and pushes go to `origin` (`Mefizz/ohealth`). Pull requests target `openhealths/nationHealth` `main`. Never push feature branches to the parent remote.

### Localization consistency
In Employee / Party UI, prefer «Працівник» over «Співробітник». Certification messages use «працівник» where the PDF does.

## Scope boundaries

In scope for this constitution amendment: Employee, Party verification, Contracts for PRIMARY_CARE (modules 3.1.1.4, 3.1.5, 3.23).

Out of scope: care plans, ePrescription, equipment 3.1.8, declarations, unrelated open PRs (#458, #387, #368). PR #481 (EmployeeRole mapMany revive) is excluded from the UAT package — live create-role 500 was fixed by #407.

## Governance

- Spec Kit artifacts live under `specs/` and `.specify/`.
- Combined UAT branch: `testing/esoz-combined-uat` (merge of #474+#480+#476+#462).
- Changes that alter certification texts require updating both code and this feature’s spec acceptance scenarios.

**Version**: 1.0.0 | **Ratified**: 2026-07-16 | **Last Amended**: 2026-07-16
