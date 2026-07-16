# Contract: Approvals (Consent)

| Operation | Method | Path |
|-----------|--------|------|
| List | GET | `/api/approvals` |
| Patient list | GET | `/api/patients/{id}/approvals` |
| Create | POST | `/api/patients/{id}/approvals` |
| Verify | PATCH | `/api/patients/{id}/approvals/{approvalId}` |
| Resend | POST | `/api/patients/{id}/approvals/{id}/actions/resend` |
| Cancel | PATCH | `/api/approvals/{id}/actions/cancel` |

## Create payload (conceptual)

- resource: `{ system: eHealth/resources, code: care_plan, value: care_plan_uuid }`
- granted_to: employee uuid (`DOCTOR` | `SPECIALIST`)
- access_level: `read` | `write`
- authorize_with: authentication method id

## Async

Create may return **202** + job. Local Approval stores temporary UUID until job `PROCESSED`, then replace with real id before verify (prevents 404).

## Verify

OTP code (or offline docs). Sandbox-only test code must never ship as production default.

## Related

- `GET /api/persons/{uuid}/authentication_methods`
- Client: `App\Classes\eHealth\Api\Approval`
- UI: `CarePlanApprovals` Livewire

## Scopes

- `approval:create` (and related read scopes as issued by auth)
