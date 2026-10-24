# Legacy Coding serialization during the FHIR rollout

The Care Plan, Service Request and Medication Request migrations add nullable
`display`, `version` and `user_selected` columns to the shared `codings` table.
Eloquent includes hydrated columns in `toArray()`, even when they are absent
from `$fillable`. Consequently, existing Clinical Impression projections gain
these keys after the migrations, including when all three values are null.

Hide the new persistence columns from the legacy model projection. Explicit
FHIR serializers can still read the attributes and emit `display`, `version`
and `userSelected` for the modules that opt into that representation. Keep the
global mass-assignment contract limited to `system` and `code`.

This compatibility change is independent of PRs #998, #1006 and #1011 and must
be deployed before their schema changes. Those PRs must persist their explicit
metadata in their repository path without widening `Coding::$fillable` or
adding global casts. No Clinical Impression business logic is changed here.

## Technical-requirement findings

The changed legacy projection is a confirmed compatibility regression, not a
proven violation of a numbered Clinical Impression technical requirement.
The existing Clinical Impression mapper reconstructs its outbound code from
`system` and `code`; a rejected Clinical Impression API request has not been
demonstrated. Shared helper methods added by the scoped FHIR work are not
called by the existing Clinical Impression flow.

Preservation of multiple codings and timestamp precision in the older
Clinical Impression import/form/export flow remains a candidate for a separate
audit. These concerns predate the scoped FHIR branches. Do not label them as
confirmed requirement violations or refactor Clinical Impression under the
Care Plan, referral or prescription PRs.

## Verification

`tests/Unit/Models/CodingSerializationTest.php` hydrates the extra columns in
memory and checks both plain and nested Clinical Impression serialization.
It also checks that explicit FHIR serializers can still read the metadata and
that global mass assignment is unchanged. These tests do not connect to a
database, create clinical records or invoke ESOZ.
