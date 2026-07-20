# Research: ESОЗ compliance package

## Decision: Combined UAT branch

**Decision**: Merge #474+#480+#476+#462 into `testing/esoz-combined-uat`.  
**Rationale**: #474 and #480 overlap on PartyVerify / lang / blade — separate checkouts produce false PASS.  
**Alternatives rejected**: Test each PR alone; mega-rebase all into one squash (harder review).

## Decision: Exclude #481

**Decision**: Out of UAT package.  
**Rationale**: User-facing employee-role create 500 (`Undefined array key ""` for empty specialityType) fixed by merged #407. `specialityMismatch` string already on main via form. mapMany null-safe remains optional hardening not required for 3.1.1/3.1.5/3.23 rem list.  
**Alternatives rejected**: Keep #481 in combined (adds noise / merge conflict surface).

## Decision: Prefer #476 party_not_verified text

**Decision**: On conflict, keep full official 3.1.1.4 multi-paragraph string from #476.  
**Rationale**: #474 carried a shortened variant that would fail PDF comparison.

## Decision: Spec Kit without CLI

**Decision**: Hand-author Spec Kit layout matching official templates.  
**Rationale**: Agent environment could not install `uv`/`specify-cli` (network 403). Structure remains compatible with later `specify init` / `/speckit.*` skills.

## Known residual risks on combined

1. Death reason codes still wrong → expected UAT FAIL C.11  
2. NEW badge «Чернетка» → FAIL C.2  
3. Rebrand leftovers → FAIL C.4  
4. PIB CONCAT search → FAIL C.16  
5. Owner deactivate still needs human UAT (PARTIAL)
