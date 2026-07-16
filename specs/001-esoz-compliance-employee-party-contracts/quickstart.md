# Quickstart: ESОЗ combined UAT

## 1. Checkout

```bash
git fetch origin
git checkout testing/esoz-combined-uat
git pull --ff-only origin testing/esoz-combined-uat   # after first push
```

## 2. Run stack

```bash
vendor/bin/sail up -d
# if frontend assets stale:
vendor/bin/sail npm run build
```

## 3. Login

- URL: `http://localhost/dev/login`
- Email: `openhealthkopylets@gmail.com`
- Legal entity: **БЕЗШЕЙКО ВІТАЛІЙ ГРИГОРОВИЧ**
- Password: `8K6siHG0JL9cNby`
- Roles: Лікар (3.1.1.4), OWNER/HR/ADMIN (3.23 / 3.1.5)

## 4. Manual script

Follow `storage/app/esoz-findings/uat-script-esoz-loop2.md`:

- Section B → expects #476 text (on this branch)
- Section C → #474+#480; expect FAIL on C.2, C.4, C.11, C.16 until gap PRs
- Section D → #462
- Ignore #481

## 5. Automated smoke (optional)

```bash
vendor/bin/sail artisan test --compact \
  tests/Feature/Party/PartyVerificationTest.php \
  tests/Feature/Party/PartyVerificationWarningCopyTest.php \
  tests/Feature/EHealth/EHealthResponseExceptionPartyNotVerifiedTest.php \
  tests/Feature/Employee/EmployeePartyNotVerifiedTest.php \
  tests/Feature/Contract/ContractRequestPolicyTest.php
```

## 6. Spec docs

- Spec: `specs/001-esoz-compliance-employee-party-contracts/spec.md`
- Inventory: `specs/_analysis/esoz-findings-inventory.md`
- Tasks (Remaining): `specs/001-esoz-compliance-employee-party-contracts/tasks.md`
