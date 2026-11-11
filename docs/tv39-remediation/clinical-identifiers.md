# Clinical identifiers: TV39 G02

Approved TV39 §1.1.7 requires RFC4122 version 1 when the MIS generates an identifier. New encounter, visit, episode, diagnostic-report, procedure, condition, observation, immunization, device, association, dispense, specimen, detected-issue and clinical-impression identifiers now use Ramsey UUIDv1 at their existing creation call sites. Existing identifiers and references are preserved; UI/cache keys remain outside this clinical requirement.

Care-plan, activity, prescription, referral, device-request and composition creation are covered by their child blocks. Approval request tracking and non-clinical contract/UI keys are outside this change.

Verification: 6 targeted clinical-identifier/dispense mapper tests, 28 assertions; Pint on 15 changed PHP files and diff check. Tests cover version, uniqueness, existing-ID preservation, and shared package IDs. Integrated module regression and role UAT run after all child blocks are included.
