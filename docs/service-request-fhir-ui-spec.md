# Module 3.17 — frontend integration specification

Issue #1005. This is a specification; the backend PR does not modify Blade/JavaScript.

## Shared resource contract

Use the existing Fhir facade/ServiceRequestMapper and ServiceRequestRequestRepository. Form values are projections of the original canonical resource. Do not reconstruct the signed JSON in JavaScript, create replacement IDs on reopen, infer reference types from labels, or discard fields absent from a screen. Server create preserves stored precise periods and full Quantity units; local draft identity remains allocated before signing.

## Service selection and basis

The selector must expose both service and service_group with the selected resource type. Preserve group references received from Care Plan Activities. Display the actual basis from both care_plan/activity references and typed context (encounter/episode_of_care); a single legacy based_on_id is a compatibility lookup, not the complete collection. Show unknown external references without replacing them with a local employee/Encounter.

Acceptance: open a service_group referral based on a plan and activity; edit only the instruction; the final request retains both basis references, the group type and all original supporting/reason/permitted references.

## Quantity and occurrence

Support decimal quantities with explicit system/code/unit. Do not use integer inputs or round to two decimals. Absence and zero are distinct; remote remainingQuantity is not a local capacity calculation. Preserve the full category coding list and text even when displaying a primary selector value.

Use an explicit period/dateTime mode, effective timezone and date/time controls. A date-only form cannot safely edit an arbitrary stored instant. Reopening an unedited value retains its original precision; changing one bound must not move the other to endOfDay, now()+hour, or a default three-month period. Display validation errors from ESOZ for expired/invalid occurrence; do not silently repair the signed document.

Acceptance: quantity 1.23456789; a period with six fractional digits; an offset dateTime; two reason references; description-only edit. Compare the stored canonical document before and after.

## Signed create, cancel and recall

Create signs the flat resource. PreQualify has service_request/programs; these envelopes are not interchangeable. Do not ask for a cancellation reason during create.

Both cancel (entered in error) and recall require a dictionary statusReason and an explanatory letter under the technical requirements. Add the missing explanation field to the cancellation flow and the missing reason selector to recall. Use the existing Livewire statusReason/referralExplanatoryLetter state. Keep the signature modal open on field errors. Credentials remain in the current KEP form only.

The backend fetches the complete fresh remote snapshot immediately before signing and adds only those two fields. A denied, failed or mismatching GET must stop the action. Do not substitute a locally reconstructed resource or remove unknown/empty fields. The current frontend cannot complete these actions until the missing fields are implemented; this is a release gate.

Acceptance: verify two independent cancellations/recalls, one per selected referral, with fresh GET each time; assert exact signed content including zero, false, unknown remote fields and precise occurrence. Simulate 403/404 and ensure no signature/submission or local success state.

## Executor actions and status

Display clinical status separately from programProcessingStatus and synchronization/job progress. Taking an active referral into work leaves canonical clinical status active. A processed async job is not itself a clinical status.

Complete sends a single completed_with Reference (Encounter/Procedure/DiagnosticReport), optional program_service/status_reason only. Existing result selection still needs full technical-requirement UAT for linked, successfully registered EMZ, service/group match, ownership and remaining-quantity confirmation.

Disable or remove the current Cancel Usage affordance pending a verified PROD Release contract. It must never invoke the signed clinical Cancel endpoint. Explain the unavailable action through the existing session flash message pattern; do not invent an alternate HTTP operation.

## Registry and privacy

Display actual requesterEmployee/requesterLegalEntity references, including unknown external authors, rather than the employee who cached a referral. Use category.coding for codes and category.text for narrative/display. Read full reference collections and exact quantity/occurrence through the repository; the historical list/filter columns are projections.

The impersonal Specimen context response must not enrich or reveal patient identity. A specimen lookup is not a normal full identified-patient import. Test that distinction before exposing that flow.

## Manual gates

Backend tests use synthetic fixtures and HTTP stubs. Before release: user-assisted KEP, dictionaries and authentication branches, identified/unidentified patient flows, both issuer and executor roles, migrated historical drafts, ambiguous-data reconciliation, Care Plan #998 integration and the required controls above. This specification does not declare 100% certification of module 3.17.


## Уточнення UAT 24.10.2026: направлення на переведення

Заклад performer береться з hospitalization.destination завершеної взаємодії. У цьому UI він readonly для узгодженості з випискою; це рішення інтерфейсу, а не твердження, що native API забороняє будь-яке редагування performer. Відображати ту саму назву з українським типом, що у спільному списку закладів, коли назви збігаються. Пояснити джерело значення під полем.

Спеціальність performer_type стосується майбутнього виконавця направлення, тому її не можна обмежувати спеціальностями автора. У формі використано SPECIALITY_TYPE й додано пояснення одержувача послуги.

Пошук підрозділів виконується через public reports registry із legal_entity_id і пагінацією. Не додавати штучну географічну рамку до пошуку конкретного закладу: старі east=180/west=-180 викликали фактичний PREPROD422, а запит лише з legal_entity_id/page/page_size успішно повертає його підрозділи. Підтримані flat data+top-level paging і data.entries+data.total_pages. Невідому форму відповіді не трактувати як порожній успішний список.

Відрізняти API failure від відсутності придатних активних підрозділів. Помилка API — error flash і пояснення у формі; успішний порожній відбір — warning flash і пояснення. Використовується Session::flash і наявний x-message. UUID закладу/підрозділу не визначати з назви та не вигадувати з opaque report ID. Після повернення API ще застосовуються перевірки власника, активності та дозволеного клінічного типу.
