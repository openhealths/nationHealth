# CarePlan / CarePlanActivity: Symfony ObjectMapper, #841

> Актуалізація 25.10.2026: роботу зведено в наявний company draft [#898](https://github.com/openhealths/nationHealth/pull/898), гілка `Mefizz:i841_object_mapper_service_request`, база main `b43c425e`. Нижче наведені історичні результати окремих інкрементів; вони не є результатами поточної консолідації. Поточний стан і відкриті перевірки — у [pr898-consolidation.md](pr898-consolidation.md).


Стан на 25.10.2026. Інкремент переносить мапінг плану лікування та призначень із фактичними callers на актуальні FHIR-контракти аудиту. Це залежний блок після #998, а не самостійна заміна його міграцій і workflow.

## База та інтеграція

- CarePlan audit #998: `e7fbd53aacaed0ccc9d7cfb0708a788aa18e63a9`.
- Main: `b43c425ecc53738460e8e3c27fcef52952f6decf`.
- Review base `19dc9d8289463885ba8cad4ab1393de6ed58ba4e` поєднує ці дві версії. Зміни main тут стосуються connections pagination та .gitignore, а не медичних контрактів.
- Перевірена реалізація: `72761eb832a14151099c66db3d60c1976fe07ab3`.
- Додаткова ціль перевірки: published combined audit `979e534727111629ca5d952ab8aba86627b9a74d`.

Власний diff потрібно читати від review base. Після злиття передумов #1022/#998 — перенести його на тодішній main, повторно перевірити зміни контрактів та CI. До того це окремий stacked draft, без повторного включення всього аудиту в company PR. Composition/PaperReferral з #1045 залишаються незалежним інкрементом.

## Архітектура

Для обох ресурсів застосовано `app/Dto/<Resource>/Model`, `Form`, `Ehealth`:

- **Model** створює канонічний ресурс для Repository зі збереженим seed. CarePlan приймає справжню Livewire CarePlanForm і FormCollection як різні source classes. Repository заздалегідь готує UUID, автора, encounter, clinical references та original resource. Activity працює з уже підготовленими полями Repository: окремої Livewire Activity Form class у цій базі немає.
- **Form** заповнює редаговані поля. CarePlan використовується під час відкриття чернетки та UI-проєкції; Activity — у фактичному Repository presenter для наявного редактора. Втрата metadata у короткому UI-представленні не перезаписує повний збережений ресурс.
- **Ehealth** явно перелічує дозволені create fields. Activity має окремий EhealthDetail, оскільки create не містить усіх полів канонічного Detail. API зберігає валідацію та native wire conversion; create DTO не пропускає server metadata.

Спільні Coding, CodeableConcept і Reference зберігають усі передані codings, text/display/version/userSelected та unknown metadata. Об'єктні колекції використовують MapCollection. ResourceData зберігає незмінені поля й literal null/false/zero; CreateData серіалізує лише mapped create fields у чинному signing order. Ці traits не виконують HTTP/SQL, не визначають користувача й не генерують час/UUID.

Збережено:

- точні незмінені Period instants і дробові секунди, локальні date formats та DST;
- coAuthors, supportingInfo, episodes, medicalRecords, parent references та explicit clearing;
- Quantity metadata, дробові кількості, remainingQuantity=0 і doNotPerform=false;
- кілька goals/reason codes/authors, typed resource references та product/schedule variant checks;
- точний create JSON, зокрема порядок detail і примусове create status/intent;
- raw cancellation snapshot і unsigned completion body на API без нової DTO-серіалізації цих документів.

SQL, графи FHIR, transactions, ownership, issuance та job handling залишилися у власників з аудиту. API endpoints, OAuth, scopes, shared jobs і схема БД інкрементом не змінені. `CarePlanMapper`, `CarePlanActivityMapper` та їхні Fhir accessors видалено після переведення всіх callers. Попередні unit assertions збережено й перенесено до tests/Unit/Mapping.

## Перевірки

1. До заміни: **43 тести / 223 assertions**, mapper/API/workflow/PostgreSQL baseline.
2. Фінальний `scripts/ci/run-domain-tests.sh` на review base з реалізацією: **808 / 3398, exit 0**, без failures/errors/skipped/risky; одне PDO deprecation та 17 наявних PHPUnit metadata deprecations.
3. На combined audit із накладеним лише цим app/tests diff: **260 / 1251, exit 0**, без failures/errors; одне PDO deprecation. Прогін охоплює Mapping, CarePlan unit/Livewire/API/persistence, Activity payload, repositories і пов'язані CarePlan/eRx workflows.
4. Після усунення дубльованих array/JSON expectations у fixtures: **68 / 394, exit 0**. Значення baseline не перегенеровано мапером; app-код порівняно з перевіреним implementation SHA не змінювався.
5. Pint перевіряє 24 нові/перенесені PHP-файли; PHP lint, shell syntax і diff check. Великі legacy компоненти/Repository не переформатовувались цілком.

Runtime: Docker PHP 8.5.3 / PostgreSQL 18, дві окремі порожні synthetic testing БД для review base й audit, internal network без зовнішнього ЕСОЗ та опублікованих портів. Штатні install/update міграції успішні на обох схемах. Source snapshot створено з Git, оригінали всіх змінених/видалених файлів перед overlay перевірено на байтову тотожність. Runner підтвердив helpers, CarePlanCreate і новий Model саме з audit root; робочі UAT-правки й БД не використовувались.

У першому широкому прогоні два eRx view tests упали через відсутній CIPHER_API_URL у disposable runner. Порожній рядок, як у .env.example, відновив baseline. Код Cipher не змінювався. Після цього завершено повний фінальний прогін; перший невдалий прогін не називається успішним.

Команда повного прогону: `CIPHER_API_URL='' bash scripts/ci/run-domain-tests.sh`. Нові mapping paths включені до чинного domain runner. PHP 8.4 CI, browser/КЕП/live eHealth UAT і вся application regression цими PHP 8.5 прогонами не підтверджені. Відповідність усім функціональним пунктам ТВ 3.10 не заявляється.

Про незалежні expectations: [care-plan-baselines.md](../tests/Fixtures/Mapping/care-plan-baselines.md).

## Що залишається в #841

Мапінг CarePlan/Activity та його фактичні callers перенесені у цьому інкременті. Далі — адаптація ServiceRequest до #1006, MedicationRequest до #1011, решта clinical/EncounterPackage ресурсів та видалення решти прикладного MedicalEvents шару після останніх callers. CarePlan approvals, validation/gates та інші workflow services не оголошуються видаленими цим mapping PR. Їхню наступну міграцію потрібно перевіряти окремими сценаріями.

Нові audit push/merge можуть змінити наведені контракти. Перед наступним інкрементом потрібна повторна звірка exact heads; старий #898 та його прогони не підміняють ці докази.
