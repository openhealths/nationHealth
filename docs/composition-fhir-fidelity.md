# TV 3.8 Composition fidelity

Composition preserves the ESOZ IL camelCase detail profile; its transport is not replaced with a generic FHIR serializer. KEP uses fresh native remote detail.

The repository persists complete type/category and Identifier type codings with display/version/userSelected, including false. Composition-owned `fhir_metadata` preserves event code/extra events and the explicit extension snapshot, so missing fields in a partial refresh preserve prior detail while explicit empty arrays clear it. The first event period remains a normalized Period with exact UTC microseconds. Composition.date has precision 6 and an owned UTC write; the shared timestamp cast and ClinicalImpression are unchanged.

The install/update migration is idempotent across the project’s migration paths. A down migration refuses destructive metadata removal. Deployment needs the shared Identifier/CodeableConcept/Period helpers from #1022. New synthetic fidelity tests use DatabaseTransactions, not the schema-reset Composition trait.

Review includes registry sync/translations and encounter preselection retained after the main frontend merge. These corrections do not claim all functional TV 3.8 cases certified; creation, approvals and human KEP remain acceptance steps.
