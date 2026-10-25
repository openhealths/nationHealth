# Independent CarePlan mapping baselines

Source: unchanged CarePlan/Activity mappers and API on review base `19dc9d8289463885ba8cad4ab1393de6ed58ba4e`, consisting of #998 `e7fbd53aacaed0ccc9d7cfb0708a788aa18e63a9` and main `b43c425ecc53738460e8e3c27fcef52952f6decf`.

- `care-plan-inputs.php` / `care-plan-baseline.json`: 42 canonical resource, hydration, round-trip, signing and negative cases. Captured before removing the original mappers. Synthetic fixtures include multiple codings, unknown metadata, explicit null/false/zero/empty lists, decimal quantities/units, DST/date formats, parent references and product/schedule invariants. Actual legacy ActivityRepository UI attributes are also captured.
- `care-plan-form-inputs.php` / `care-plan-form-baseline.json`: six actual Livewire Form cases (create/edit, three date formats), with Repository-prepared identity/author/references. Captured using the exact original CarePlanMapper from the Git-pinned review base in isolation. Expectations were not generated with the new Model or Ehealth DTO.
- `care-plan-api-baseline.json`: eight create payload cases captured with the original APIs from the same commit, including reversed/root-first field ordering and excluded server metadata. Only temporary legacy class names were changed to allow isolated loading; the original API mapping and validation methods were used.

Exact signing JSON uses `JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION`. Tests compare both the captured bytes and decoded arrays; credentials from synthetic forms must not enter the output. Ready canonical seed documents are retained separately from the UI projection and endpoint create allowlist.

The expectations store captured JSON once. Redundant pretty-printed copies of the same decoded arrays were removed after verifying equality; this changed fixture representation, not their expected values. The original full captures are retained in commit `72761eb832a14151099c66db3d60c1976fe07ab3`.

SHA-256 before compaction: `care-plan-baseline.json` = `38a53707979a85c40119908aa35988f5badb84adcd7a42f7f0417bf2bafa5004`; `care-plan-form-baseline.json` = `6721fd0a8cd694dc930095b5cee6529eef29deae46a0579c2cdad45e835ef822`; `care-plan-api-baseline.json` = `d1b3d9c6da2c9c37cc96a02fbcae7550b7ad3b3841f897d7d395913117205359`.

These fixtures preserve the audited contracts, not every conceivable behavior. They do not establish complete TV conformance, concurrency safety, browser/KEP or live eHealth acceptance. A known contract defect must be fixed explicitly with its own evidence rather than accepted solely because it appears in a golden file.
