# Contract: Service & Device Requests (електронні направлення)

Base: `/api/patients` (`PatientApiBase`)

## Service Request

| Operation | Method | Path |
|-----------|--------|------|
| Create signed | POST | `/api/patients/{id}/service_requests` |
| Prequalify | POST | `/api/patients/{id}/service_requests/prequalify` |
| Get | GET | `/api/patients/{id}/service_requests/{srId}` |
| Cancel | PATCH | `.../actions/cancel` |
| Resend | POST | `.../actions/resend` (якщо передбачено API) |

## Device Request

| Operation | Method | Path |
|-----------|--------|------|
| Create signed | POST | `/api/patients/{id}/device_requests` |
| Prequalify | POST | `/api/patients/{id}/device_requests/prequalify` |
| Get / Cancel / Resend | analogous | |

## based_on (обов’язково)

```json
[
  { "identifier": { "type": { "coding": [{ "system": "eHealth/resources", "code": "care_plan" }] }, "value": "<care_plan_uuid>" } },
  { "identifier": { "type": { "coding": [{ "system": "eHealth/resources", "code": "activity" }] }, "value": "<activity_uuid>" } }
]
```

## Catalog constraints (з ТЗ 3.2.2 / 3.3.1)

- ЕН `code`: послуги/групи з `request_allowed=true`
- Active services для actions/procedures: `is_active=true`

## Paper referral (СМД encounter context)

Обов’язкові: `requester_legal_entity_edrpou`, `requester_employee_name`, `service_request_date`  
Опційні: `requisition`, `requester_legal_entity_name`, `note`

## Clients / services

- `App\Classes\eHealth\Api\Patient\ServiceRequest`
- `App\Classes\eHealth\Api\Patient\DeviceRequest`
- `ReferralRequestLifecycleService`
