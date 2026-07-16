# Contract: Care Plan & Activities

## Care Plan

| Operation | Method | Path |
|-----------|--------|------|
| Create (signed) | POST | `/api/patients/{patientId}/care_plans` |
| List (patient) | GET | `/api/patients/{patientId}/care_plans` |
| List (global search) | GET | `/api/care_plans` |
| Details | GET | `/api/patients/{patientId}/care_plans/{id}` |
| Cancel | PATCH | `/api/patients/{personId}/care_plans/{id}/actions/cancel` |
| Complete | PATCH | `/api/patients/{personId}/care_plans/{id}/actions/complete` |

**Create body**: `signed_data` (КЕП) over care plan resource. Resource MUST include subject, author, terms_of_service, period, category, intent, addresses, inform_with (as applicable). MUST NOT include unknown fields (e.g. local `clinical_protocol` / `instantiates_protocol`). Plan is created **without** activities (API-007-005-0001).

**Complete body** (API-007-005-0006): `status_reason` from `care_plan_complete_reasons` — **no digital signature**. Preconditions: write Approval; all activities final; ≥1 activity `completed`.

**Cancel body** (API-007-005-0005):  
1. `GET .../care_plans/{id}` (Get Care Plan by ID) — **required**  
2. Clean payload (no activities; strip server-only fields; normalize author)  
3. Add `status_reason` (`care_plan_cancel_reasons`) into content to sign  
4. Sign → `PATCH .../actions/cancel` with `signed_data`  

Only **author** + write Approval. All activities final (or none). Fail closed if Get fails.

**Canonical docs**: [references.md](../references.md)  
**Code reference**: mirror `CarePlanShow::signStatusActivity` (activity already CBD-first); plan cancel currently still local-built — target fix.

**Client**: `App\Classes\eHealth\Api\CarePlan`

## Care Plan Activity

| Operation | Method | Path |
|-----------|--------|------|
| Create | POST | `/api/patients/{patientId}/care_plans/{carePlanId}/activities` |
| Summary | GET | `.../activities` |
| Details | GET | `.../activities/{activityId}` |
| Cancel | PATCH | `.../activities/{id}/actions/cancel` |
| Complete | PATCH | `.../activities/{id}/actions/complete` |

**Kinds**: `service_request` | `medication_request` | `device_request`

**Client**: `App\Classes\eHealth\Api\CarePlanActivity`

## Scopes

- `care_plan:read`
- `care_plan:write`

## Error handling

- Schema validation → show System message, allow edit+retry
- 403 → consent CTA
- 202 + job link → poll `/api/jobs/{job_uuid}`
