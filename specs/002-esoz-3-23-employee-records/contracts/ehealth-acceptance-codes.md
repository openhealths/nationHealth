# Contracts / acceptance codes — 3.23

## Party verification death update (3.23.3.4)

| Action | verification_status | verification_reason | comment |
|--------|---------------------|---------------------|---------|
| Confirm death | VERIFIED | MANUAL_CONFIRMED | required (product) |
| Refute death | VERIFIED | MANUAL_NOT_CONFIRMED | required (product) |

UI: `app/Livewire/Party/PartyVerify.php`  
Lang: `resources/lang/uk/party_verification.php`

## Employee request status (local vs eHealth)

| Local meaning | Preferred status | Notes |
|---------------|------------------|-------|
| Draft not submitted | NEW, uuid=null | Editable |
| Submitted, awaiting eHealth | NEW, uuid set | Prefer #493; avoid SIGNED label hack |
| Legacy submitted | SIGNED | Treat as pending until migrated |
| Accepted | APPROVED | Employee record |
| Rejected / expired | REJECTED / EXPIRED | Terminal |

## Deactivate Employee (3.23.4)

| UI status | end_date |
|-----------|----------|
| STOPPED | required; start_date ≤ end_date ≤ today |
| ENTERED_IN_ERROR | must omit |
