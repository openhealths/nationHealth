# Device requests: TV39 remediation

Issue: #1205. Umbrella: #1201. Requirements: approved edition 39 only.

## Changes

- D01: a device activity without a medical program may omit scheduled_period. A provided activity period remains validated, a medical-program activity still needs its period, and the care-plan period always constrains the request. The preparation path applies optional activity bounds only when present.
- D02 / U08: signing errors keep the KEP modal and its fields available. Field validation and concurrent-submission failures stop before external reads/writes; catalogue refresh failures cannot hide the original signing error. The modal closes and credentials clear after confirmed remote creation.
- G02 (device-request scope): new locally generated clinical IDs use RFC4122 version 1. Existing request IDs remain unchanged.

## Verification

The isolated PostgreSQL database tv39_1205 uses install migrations only. No development patient database migrations or records were changed. Feature tests exercise absent, empty, future and expired activity periods; program-required periods; full preparation with activity and plan boundaries; real Livewire field feedback; and a held signing lock without remote or database side effects. Existing device, dispense, patient UI, mapper and prescribing tests are included in the broader run.

The full G01 response-contract work is an umbrella dependency, including Device Request writes/details/dispenses, configurations and auth methods. Real certificate signing and live SMS are reserved for user-assisted acceptance; automated tests do not certify those external outcomes.
