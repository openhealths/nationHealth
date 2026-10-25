# Symfony ObjectMapper: стан #841 на 25.10.2026

> Актуалізація 25.10.2026: роботу зведено в наявний company draft [#898](https://github.com/openhealths/nationHealth/pull/898), гілка `Mefizz:i841_object_mapper_service_request`, база main `b43c425e`. Нижче наведені історичні результати окремих інкрементів; вони не є результатами поточної консолідації. Поточний стан і відкриті перевірки — у [pr898-consolidation.md](pr898-consolidation.md).


Рефактор оновлено від main `b43c425ecc53738460e8e3c27fcef52952f6decf`. Поточний інкремент переносить Composition та PaperReferral у DTO за патерном Division/HealthcareService. Він не містить повторної копії FHIR-стеку з аудиту й не означає завершення міграції всіх медичних сервісів.

## Реалізовано

- `Composition/Ehealth`: створення МВН і МВТН із реальних Livewire Form, один target для кількох source classes; Event/Extension через MapCollection.
- `Composition/EhealthCancellation`: чинний native контракт скасування. Видалено BuildsCompositionPayload, форми делегують DTO.
- `Composition/Form`: заповнення уточнення та продовження МВТН із попереднього документа. Збережено dates, relation, окремий focus, explicit false та правила очищення полів продовження. ExtensionValue — чисте читання typed value.
- `PaperReferral/Ehealth` і `Form`: outbound та hydration у фактичних ProcedureMapper/DiagnosticReportMapper. Видалено PaperReferralMapper. SourceHasPath розрізняє missing та explicit null.
- DTO не виконують SQL/HTTP, не генерують UUID і не визначають поточного користувача. Native payload names, підписаний документ, API, Repository та workflow залишаються у чинних власників.

## Перевірки

На main-базі та окремо на зафіксованому опублікованому audit snapshot `979e534727111629ca5d952ab8aba86627b9a74d` із накладеним лише цим app/tests diff отримано однаковий результат: **94 тести, 384 assertions, exit 0, без failures/errors**. Є одне наявне deprecation notice PDO; це не повністю чистий від notices прогін.

Знімок аудиту створено з Git commit, без локальних незакомічених UAT-правок. Перед накладанням diff перевірено байтову тотожність оригіналів усіх змінюваних/видалених файлів між main-базою та аудитом. Окремий autoloader підтвердив, що форми й helpers завантажено саме зі snapshot. Робоча UAT-гілка та її БД не змінювались.

Склад прогону:

- `tests/Unit/Mapping`: 43 незалежні baseline cases (14 create/cancel, 9 paper referral, 20 refinement/continuation) і 3 додаткові literal Extension cases. Перевіряються exact arrays/JSON, фактичні callers, незмінність source, відсутність SQL та HTTP.
- Чинні `CompositionPayloadTest`, `CompositionFormTest`, `CompositionTempDisabilityFormTest`, `CompositionApiTest`, `CompositionResponseValidationTest`.
- PHP lint і точковий Pint для нових/перенесених класів. Весь великий legacy parent mapper заради форматування не переписувався.

Ці самі mapping/Composition paths додані до явного переліку `scripts/ci/run-domain-tests.sh`, щоб нові контрактні тести виконувались у чинному GitHub/Azure domain CI. Решта переліку та налаштування jobs не змінені. Результат локального прогону не підміняє статус актуального CI.

Прогін виконано в ізольованому PHP 8.5 Docker runner без мережі та БД; API-тести використовують fake HTTP. Це не повний application regression, не PostgreSQL round-trip і не перевірка браузера, реального ЕСОЗ чи КЕП. Для майбутніх persistence/workflow інкрементів потрібні окремі докази.

Походження expectations: [fixtures README](../tests/Fixtures/Mapping/README.md). Вони зняті до заміни реалізації; поточний DTO їх не генерує.

## Що залишилось

1. Після узгодження спільної бази #1022 переносити актуальні канонічні перетворення CarePlan/Activity (#998), ServiceRequest (#1006), MedicationRequest (#1011). Контракти уточнюються за кожним новим head, а не за старим draft #898.
2. Зберегти вже реалізоване в аудиті fresh-detail signing та відновлення eRx після прийнятого підпису без повторного КЕП; structured dosage/units та distinct MRR/MR UUID не спрощувати.
3. Для Composition full detail/persistence узгоджуватися з #1042. Редагована форма не замінює повний ресурс з metadata, усіма codings та правилами partial import.
4. Далі переносити решту clinical/EncounterPackage маперів фактичними вертикальними сценаріями. Device #1016/#1030/#1031 перевіряти окремо: вони не входять до протестованого комбінованого snapshot.
5. Видаляти старі мапери та прикладні MedicalEvents services після останнього caller і відповідних regression checks. Зараз у цьому шарі **41 PHP-файл і 16 legacy-маперів** проти 42/17 у базі; повне видалення ще попереду.

Детальні межі та критерії наступних інкрементів: [план](ehealth-object-mapper-plan.md). Порядок audit-залежностей: #1022 → #998 → #1006 → #1011 → #1042. Вони є цільовими контрактами до злиття, а не твердженням, що всі ці PR уже схвалені чи присутні в main.
