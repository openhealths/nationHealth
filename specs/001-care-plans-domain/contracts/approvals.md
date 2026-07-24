# Contract: Approvals (Consent)

| Operation | Method | Path |
|-----------|--------|------|
| List (global search) | GET | `/api/approvals` |
| List / sync (canonical) | GET | `/api/patients/{id}/approvals` |
| Create | POST | `/api/patients/{id}/approvals` |
| Verify | PATCH | `/api/patients/{id}/approvals/{approvalId}` |
| Resend | PATCH or POST (confirm UAT) | `/api/patients/{id}/approvals/{id}/actions/resend` |
| Cancel | PATCH | `/api/approvals/{id}/actions/cancel` |

**Get approvals filters** (prefer server-side): `granted_resource_type=care_plan`, `granted_resources={uuid}`, `granted_to`, `status`, `access_level`, paging.  
Docs: [Get approvals](https://e-health-ua.atlassian.net/wiki/spaces/EH/pages/2115600961/Get+approvals), [Resend SMS](https://e-health-ua.atlassian.net/wiki/spaces/EH/pages/583403110/Resend+SMS+on+Approval).  
Architecture gap: [analysis-approvals-architecture-gap.md](../analysis-approvals-architecture-gap.md)

## Create payload (conceptual)

- resource: `{ system: eHealth/resources, code: care_plan, value: care_plan_uuid }` (approval for care_plan MUST NOT mix other entities)
- granted_to: employee uuid
- access_level: `read` | `write`
- authorize_with: authentication method id (optional; default MPI method)

### Write on care_plan (CSI-1323)

Allows: create activities; cancel medication requests; recall/cancel service requests based on care plan.  
`write` only if granted employee’s LE = care plan `managing_organization`.

### Auth method skip

If care_plan `terms_of_service=INPATIENT` and granted employee LE = managing_organization → skip person auth_method / urgent null.

## Async

Create may return **202** + job. Local Approval stores temporary UUID until job `PROCESSED`, then replace with real id before verify (prevents 404).

## Verify

OTP code (or offline docs). Sandbox-only test code must never ship as production default.

## Related

- `GET /api/persons/{uuid}/authentication_methods`
- Client: `App\Classes\eHealth\Api\Approval`
- UI: `CarePlanApprovals` Livewire (domain)
- Shared: `HasApproval` (PatientData), `ApprovalRepository`, `RemoteEHealthLinksProcessing`

## Scopes

- `approval:create`
- `approval:read` (Get approvals / sync)
