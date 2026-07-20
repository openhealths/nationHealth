# Implementation Plan: ESОЗ Compliance — Employee, Party, Contracts

**Branch**: `testing/esoz-combined-uat` | **Date**: 2026-07-16 | **Spec**: `./spec.md`

## Summary

Deliver a single UAT branch that merges certification fixes from PRs #474 (employee/party), #480 (warning copy), #476 (403 message), and #462 (PRIMARY_CARE contracts). Document Remaining gaps as follow-up tasks without rewriting Done work. Exclude #481.

## Technical Context

**Language/Version**: PHP 8.5  
**Primary Dependencies**: Laravel 12, Livewire 3, existing eHealth API client  
**Storage**: PostgreSQL (Sail)  
**Testing**: PHPUnit Feature/Unit via `vendor/bin/sail artisan test`  
**Target Platform**: Web MIS (Nation Health)  
**Project Type**: Laravel monolith (existing Laravel 10-era structure)  
**Constraints**: Sail-only; fork-only feature pushes; PDF-literal UK strings  
**Scale/Scope**: Certification modules 3.1.1.4, 3.1.5, 3.23 only

## Constitution Check

| Gate | Status |
|------|--------|
| PDF source of truth for acceptance texts | PASS |
| Done vs Remaining separated | PASS |
| Sail-only / fork-only | PASS |
| #481 excluded from UAT package | PASS |
| Combined branch avoids multi-PR UAT confusion | PASS |

## Project Structure

### Documentation (this feature)

```text
specs/001-esoz-compliance-employee-party-contracts/
├── spec.md
├── plan.md
├── research.md
├── quickstart.md
├── checklist.md
├── tasks.md
├── analyze.md
└── contracts/
    └── ehealth-acceptance-codes.md
specs/_analysis/
└── esoz-findings-inventory.md
.specify/memory/constitution.md
```

### Source Code (existing app)

```text
app/Livewire/Employee/**
app/Livewire/Party/**
app/Livewire/Contract*/**
app/Policies/Employee*.php
app/Policies/ContractRequestPolicy.php
app/Exceptions/EHealth/**
resources/lang/uk/{errors,forms,party_verification,contracts,validation}.php
resources/views/livewire/{employee,party,contract*}/**
tests/Feature/{Employee,Party,Contract,EHealth}/**
```

**Structure Decision**: Keep existing Laravel layout; no new top-level app folders.

## Combined branch composition

| Order | Source branch | PR | Notes |
|------|---------------|-----|-------|
| 1 | `fork/main` | — | base |
| 2 | `testing/combined-employee-fixes` | #474 | employee/party |
| 3 | `i479_party_verification_warning_copy` | #480 | warnings |
| 4 | `i475_fix_party_not_verified_403_message` | #476 | prefer full party_not_verified |
| 5 | `i433_nhsu_contract_duration_validation` | #462 | contracts |

Conflict policy already applied: keep #476 official 3.1.1.4 text; keep employee speciality translation maps + contract medical_program handlers.

## Complexity Tracking

| Violation | Why Needed | Simpler Alternative Rejected Because |
|-----------|------------|-------------------------------------|
| Multi-PR merge branch | Manual UAT needs one deploy | Testing 4 PRs separately hides overlaps (#474∩#480) |
