# G01: shared eHealth response boundaries

The registry in `resources/ehealth/contracts/shared-responses.json` retains the complete successful response schemas for 89 operations from the official archived `preprod/medical-events.json` and `preprod/general-mis.json`. Each operation retains its native fields; adaptation happens only after validation. This covers the eleven EMZ resources, patient device requests and dispenses, episodes, active diagnoses, configurations, dictionaries, medical programs and person authentication methods. CarePlan, ServiceRequest and Composition have separate owning validators.

`SchemaResponseContract::validateData($value, $schema, $components)` is reusable without medication assumptions. `MedicationResponseContract` delegates to the same evaluator with the medication registry. The evaluator enforces standard `allOf`, `oneOf`, `anyOf`, nullability, schema-valued `additionalProperties`, nested required fields, primitive types, enums and documented formats. RFC3339 date-time validation rejects invalid calendar dates, times and missing timezone offsets. It preserves the native response for signing.

Explicit provider-document reconciliations are scoped to registry entries, never applied as a global relaxation:

- Approved TV39 §3.20.2.2.3.2 and §3.20.3.1.5 permit quantityless device requests only without a medical program and with a classification `code`. Program and model requests retain required quantities.
- Native `allOf` response definitions that declare `additionalProperties: false` separately from sibling properties have those declared properties normalized into their closed object. Arbitrary input schemas retain standard closed-object semantics.
- Medical Events paging uses `page`; General MIS uses `page_number`. Both documented names normalize to the existing paging consumer. Conflicting aliases, invalid integer bounds and missing paging fields are rejected.
- Dictionary child examples document `code`, `description` and `is_active`, while parent values additionally require IDs and audit timestamps. Recursive child values retain their own complete documented shape and optional audit-field validation.
- Encounter participant and medical-program allowed-code-type examples supplement their documented enum alternatives explicitly. Device dispense detail example literals are reconciled with the same snapshot's named response definitions for variable quantities and price fields.
- Configuration `settings` remains a native JSON string. Observation synchronization decodes it only after full validation and writes only after every page and settings object has passed.

Every changed API consumer validates before mapping, persistence or UI state transitions. Dictionary refreshes validate all pages before replacing prescribing rules. Device imports verify patient identity before database writes. Submitted clinical job receipts require canonical statuses and a job link in the expected path context; pending outcomes remain unconfirmed. Prequalification requires complete medical-program entries and explicit valid statuses.

Verification includes official native examples, all affected API collection and summary boundaries, malformed nested fields, pagination, jobs, prequalification, and configuration/cache atomicity. The domain CI runner includes `tests/Unit/eHealth` and the DeviceRequest feature directory. Tests use an isolated database and prevent stray HTTP requests. Real KEP, SMS delivery and provider acceptance remain user-assisted integration verification.
