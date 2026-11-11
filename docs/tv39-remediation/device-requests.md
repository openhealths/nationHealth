# Device requests: TV39 remediation

Issue: #1205. Umbrella: #1201. Requirements: approved edition 39 only.

## Changes

- D01: a device activity without a medical program may omit scheduled_period. A provided activity period remains validated, a medical-program activity still needs its period, and the care-plan period always constrains the request. The preparation path applies optional activity bounds only when present.
- D02 / U08: signing errors keep the KEP modal and its fields available. Field validation and concurrent-submission failures stop before external reads/writes; catalogue refresh failures cannot hide the original signing error. The modal closes and credentials clear after confirmed remote creation.
- G02 (device-request scope): new locally generated clinical IDs use RFC4122 version 1. Existing request IDs remain unchanged.
- BA08: the encounter form selects an existing eligible care plan and activity through the shared provider, using the exact selected performer and current policies. Approval changes refresh choices; save/sign reread eligibility before effects. Native remaining quantity and units are displayed. Confirmed, verified, unexpired write access is independent of the local approval status label.
- BA09: device definitions validate the documented optional manufacturer object before mapping. Detail views display its actual name and COUNTRY dictionary value. Program participation is checked separately and is never substituted for the manufacturer.
- G01 prerequisites: native Device Request and Device Definition reads, delegated client results and signing data are validated before mapping, persistence, UI updates or job dispatch. Malformed responses remain covered by negative tests.

TV39 3.20.2.2.2 still requires the request's occurrence period. The D01 relaxation concerns only an absent scheduled period of a care-plan activity without a program; supplied bounds and program/plan limits remain enforced. Existing-plan use follows TV39 3.10.2.1: write approval and employment at the same institution as the plan author, with the exact eligible current actor rather than historical-author equality.

## Verification

The final isolated PostgreSQL run in `tv39_1205` passes **180 tests / 822 assertions**, including device, dispense, patient UI, native mapper, program participation, prescribing and selected-employee regressions. Tests exercise absent, empty, future and expired activity periods; program-required periods; preparation with activity and plan boundaries; Livewire field feedback; and a held signing lock without remote or database side effects. Pint passes all **42 PHP/Blade files in the full PR diff**. Import/class resolution, repository factories, added authorization calls and `git diff --check` pass. Existing PHPUnit deprecations remain separate from failures. No development patient database migrations or records were changed.

| Existing surface | Evidence and acceptance boundary |
|---|---|
| Interaction | Existing device documents/statuses remain displayed. Creation resolves eligible finished-today encounters and the exact current performer. Manual SMD/PMD/current-role checks of actions and same-document/source navigation remain pending. |
| Patient card | Patient registry and detail rendering, native statuses, basis, manufacturer and denial without effects are covered by the final suites. Device endpoints require an identified person; no remote preperson support is claimed. Manual role/feedback acceptance remains pending. |
| Sidebar | Existing partial module visibility is controlled by shared layout/module flags and current policies. No additional mandatory menu count is introduced; manual institution/role visibility remains pending. |

Shared G01 cross-module acceptance remains coordinated with the umbrella. Real certificate signing, live SMS and manual browser acceptance remain user-assisted; automated tests do not certify those external outcomes.
