# eHealth acceptance codes (contracts for certification)

## Party death verification (3.23.3.4)

| Action | verification_status | verification_reason | verification_comment |
|--------|---------------------|---------------------|----------------------|
| Confirm death | `VERIFIED` | `MANUAL_CONFIRMED` | required (per issue notes) |
| Refute death | `VERIFIED` | `MANUAL_NOT_CONFIRMED` | required |

**Forbidden on UAT-complete build**: `MANUAL_DECEASED`, `MANUAL_NO_DEATH_RECORD`.

## Party not verified (3.1.1.4)

| HTTP | API message contains | UI key |
|------|----------------------|--------|
| 403 | `Party is not verified` | `errors.ehealth.messages.party_not_verified` |

## Payment methods (3.1.5)

| Code | UK label |
|------|----------|
| `FORWARD` | Попередня оплата |
| `BACKWARD` | Післяплата |

## PRIMARY_CARE list labeling

| Internal type | UI label for PRIMARY_CARE |
|---------------|---------------------------|
| `REIMBURSEMENT` | Капітація |
