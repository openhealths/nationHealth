# Feature Specification: Плани лікування та пов’язані сутності (Care Plans Domain)

**Feature Branch**: `spec_care_plans_domain`  
**Feature Directory**: `specs/001-care-plans-domain`  
**Created**: 2026-07-16  
**Status**: Draft  
**Input**: Технічні вимоги МІС до робочого місця лікаря ПМД/СМД (п. 3.2–3.3: передумови ЕМЗ, ЕН, approvals) + домен планів лікування, призначень, електронних направлень, електронних рецептів і дозволів (approvals) у ЕСОЗ.

## Scope & Source Mapping

| Джерело | Що беремо в цю специфікацію |
|---------|-----------------------------|
| п. 3.2 / 3.3 (вставлене ТЗ) | Передумови: пацієнт у реєстрі, працівник/ролі, довідники; робота з ЕМЗ (епізод, пакет взаємодії, діагнози); створення/погашення **ЕН**; доступ через **approval** з рівнем `write`; каталог послуг |
| Офіційні Confluence / Apiary | Канонічні API-правила Create/Complete Care Plan, Activity, Approval, PreQualify MRR — див. [references.md](./references.md) |
| Домен Care Plan (ЕСОЗ + поточна МІС) | План лікування, activities (призначення), approvals на план, Service/Device Request, Medication Request |
| Поза scope цього feature | Повний CRUD декларацій (3.2.1), реорганізація закладу, MedData COVID autofill як окремий модуль, медичні висновки (3.8) |

**Мета**: МІС забезпечує лікарю ПМД (`DOCTOR`) та СМД (`SPECIALIST`) повний життєвий цикл **плану лікування** і похідних документів (призначення → направлення / е-рецепт / призначення виробу) з дотриманням згоди пацієнта.

## Clarifications

### Session 2026-07-17

- Q: Які офіційні джерела є канонічними для імплементації? → A: Confluence API docs + Apiary з [references.md](./references.md) (NotebookLM — лише збірник лінків, без окремого експорту в git).
- Q: Чи потрібен КЕП для Complete Care Plan? → A: Ні — API-007-005-0006: «Complete performs without DS»; потрібні write Approval + `status_reason` + фінальні activities (усі final, ≥1 completed).
- Q: Чи створюється план одразу з activities? → A: Ні — Create Care Plan без activities; activities додаються окремим Create Activity (одна на запит, з КЕП + write Approval).
- Q: Чи потрібен КЕП для Cancel Care Plan? → A: Так — API-007-005-0005: DS обов’язковий; підписується план без activities; `status_reason` у signed content; лише автор + write Approval; activities усі final або відсутні.
- Q: Звідки брати контент для підпису Cancel Care Plan? → A: Обов’язково `Get Care Plan by ID` з ЕСОЗ, потім підписувати CBD-знімок + `status_reason` (без activities). Патерн як у `CarePlanShow::signStatusActivity` (getDetails → clean → sign). Локальна збірка payload для cancel плану — заборонена як primary path (часта 422 Signed content doesn't match).
- Q: Як оформити Complete в UI? → A: Окремий flow без КЕП (модалка: причина + підтвердження); Cancel — окремо через signature modal з КЕП.
- Q: Хто бачить кнопку «Скасувати план»? → A: Cancel — лише автор плану; Complete — автор або працівник того ж LE з write Approval.
- Q: З чого починати імплементацію першим? → A: Спочатку US1–US3 (create plan / approvals / activities), потім US6 Cancel/Complete плану.
- Q: Чи потрібен явний Approval автору плану для activities? → A: Автор плану (той самий LE) — без окремого Approval, доки ЕСОЗ не відмовить (403 → CTA на Approval); інші клініцисти — лише з verified write Approval.

---

## User Scenarios & Testing *(mandatory)*

### User Story 1 — Створити та зареєструвати план лікування (Priority: P1)

Лікар ідентифікує пацієнта, обирає завершену взаємодію (Encounter) з діагнозами, заповнює обов’язкові поля плану (умови надання послуг, категорія, період, intent), підписує КЕП і реєструє план у Системі. МІС показує статус і зберігає ідентифікатор плану в контексті пацієнта.

**Why this priority**: Без активного/зареєстрованого плану неможливі призначення, направлення та рецепти в рамках care plan.

**Independent Test**: На тестовому пацієнті з finished encounter створити й підписати план; у UI та Системі з’являється план зі статусом `new` або `active` і валідним UUID.

**Acceptance Scenarios**:

1. **Given** користувач з роллю `DOCTOR` або `SPECIALIST` у активному закладі та пацієнт з finished Encounter у ЕСОЗ з діагнозами, **When** створює план і підписує КЕП, **Then** МІС реєструє план у Системі, зберігає UUID/статус локально і показує успіх.
2. **Given** Encounter без діагнозів або не синхронізований у ЕСОЗ, **When** лікар намагається підписати план, **Then** МІС блокує відправку і пояснює, яких передумов бракує.
3. **Given** Система повертає помилку валідації, **When** створення/підпис не вдався, **Then** МІС показує текст помилки Системи і дозволяє виправити дані для повторного запиту.
4. **Given** план успішно створено, **When** лікар відкриває картку пацієнта / список планів, **Then** план знаходиться за ідентифікатором і відображає ключові реквізити (статус, період, автор, умови надання).

---

### User Story 2 — Отримати доступ (Approval) до плану лікування (Priority: P1)

Якщо немає активної декларації з цим лікарем/закладом (або потрібен write-доступ іншому клініцисту), лікар запитує Approval на ресурс `care_plan`, пацієнт підтверджує OTP (або offline), після верифікації лікар може читати/писати призначення.

**Why this priority**: Без verified approval (або implicit declaration access) ЕСОЗ повертає `403 Access denied` на activities / направлення / рецепти.

**Independent Test**: На пацієнті без декларації створити approval → verify OTP → успішно додати activity; без verify — отримаємо блокування з поясненням.

**Acceptance Scenarios**:

1. **Given** активний план і відсутній write-доступ, **When** лікар створює Approval на `care_plan` для себе (`DOCTOR`/`SPECIALIST`), **Then** МІС ініціює запит, відстежує асинхронний job і показує очікування коду.
2. **Given** Approval у стані очікування коду, **When** лікар вводить коректний OTP, **Then** Approval стає verified і write-операції дозволені.
3. **Given** повідомлення не надійшло, **When** лікар натискає «Надіслати повторно», **Then** МІС викликає resend згідно API Системи і інформує про результат (з обмеженнями Системи).
4. **Given** обрано співробітника типу OWNER/HR, **When** створюється Approval, **Then** МІС не дозволяє / показує зрозумілу помилку (дозволені лише клінічні типи).
5. **Given** лікар того ж СГуСОЗ має write approval на взаємодію/план, **When** виконує дозволені write-дії, **Then** Система приймає запит (узгоджено з п. 3.2.2.8 / 3.3.1.11 щодо approvals).

---

### User Story 3 — Керувати призначеннями (Care Plan Activities) (Priority: P1)

На активному плані лікар створює призначення одного з видів: послуга (`service_request`), лікарський засіб (`medication_request`), медичний виріб (`device_request`); підписує activity у Системі; може скасувати або завершити activity з обов’язковою причиною з довідника.

**Why this priority**: Activity — місток між планом і конкретним ЕН / е-рецептом / device request.

**Independent Test**: Додати й підписати activity кожного kind; перевірити статуси `scheduled`/`in-progress` і блокування виписки без реєстрації activity в ЕСОЗ.

**Acceptance Scenarios**:

1. **Given** план `active` і write-доступ, **When** лікар створює й підписує activity, **Then** activity з’являється в Системі з UUID і статусом, придатним для виписки документа.
2. **Given** activity ще лише локальний draft, **When** лікар намагається виписати ЕН/рецепт, **Then** МІС блокує дію з поясненням «спочатку зареєструйте призначення в ЕСОЗ».
3. **Given** підписана activity, **When** лікар скасовує/завершує з причиною з довідника, **Then** статус оновлюється в Системі й локально; помилки Системи показуються з можливістю повтору.
4. **Given** кількість/ліміти програми (для medication/device), **When** залишок вичерпано, **Then** МІС не дозволяє нову виписку і пояснює ліміт.

---

### User Story 4 — Виписати електронне направлення (Service / Device Request) (Priority: P2)

З activity типу послуга або виріб лікар формує електронне направлення (ЕН): prequalify (де вимагає Система), підпис КЕП, відображення статусу, повторне OTP/SMS, скасування, пошук ЕМЗ погашення (якщо дані вже є — п. 3.2.2.15 / 3.3.1.19).

**Why this priority**: Основний клінічний результат плану для маршрутизації пацієнта; залежить від US1–US3.

**Independent Test**: З `service_request` activity створити й підписати Service Request; для device — обов’язковий prequalify перед create.

**Acceptance Scenarios**:

1. **Given** activity `service_request` у ЕСОЗ, **When** лікар створює ЕН з послугою/групою де `request_allowed=true` (п. 3.2.2.18) і підписує, **Then** ЕН реєструється, статус відображається, є можливість resend/cancel згідно правил Системи.
2. **Given** activity `device_request`, **When** програма вимагає prequalify, **Then** МІС виконує prequalify до створення; при `INVALID` показує причину і не створює документ.
3. **Given** взаємодія СМД за ЕН, **When** лікар вказує `incoming_referral` або заповнює `paper_referral` (п. 3.3.1.20), **Then** обов’язкові поля паперового направлення контролюються МІС.
4. **Given** лікар-автор ЕН, **When** переглядає пацієнта, **Then** може знайти ЕМЗ, з якими направлення погашено, якщо дані доступні на момент перегляду.
5. **Given** помилка Системи при create/sign/cancel, **When** запит неуспішний, **Then** користувач бачить текст помилки і може виправити дані / повторити.

---

### User Story 5 — Виписати електронний рецепт (Medication Request) (Priority: P2)

З activity `medication_request` лікар формує заявку на рецепт (MRR): prequalify для `intent=order` за програмою, створення, підпис КЕП → активний MR; відхилення MRR/MR через reject (не «cancel» як бізнес-операція); друк / повтор OTP.

**Why this priority**: Критичний compliance-шлях реімбурсації; залежить від US1–US3.

**Independent Test**: Пройти prequalify → create MRR → sign → MR `ACTIVE`; reject NEW MRR і ACTIVE MR з причиною з довідника.

**Acceptance Scenarios**:

1. **Given** activity `medication_request` з `product_reference` на INNM і програмою, **When** `intent=order`, **Then** МІС виконує PreQualify до Create і показує VALID/INVALID програм.
2. **Given** VALID prequalify, **When** лікар створює й підписує MRR, **Then** створюється MR зі статусом `ACTIVE`, локально зберігаються ідентифікатори й період лікування.
3. **Given** MRR у статусі `NEW`, **When** лікар відхиляє заявку, **Then** статус `REJECTED` через Reject MRR API.
4. **Given** MR `ACTIVE`, **When** лікар відхиляє рецепт, **Then** використовується Reject MR з `reject_reason_code`, а не довільний cancel без довідника reject-причин.
5. **Given** `based_on`, **When** формується запит, **Then** передаються посилання на `care_plan` і `activity` (окремо), контекст encounter за потреби Системи.
6. **Given** обрано метод інформування пацієнта, **When** рецепт створено, **Then** доступні повторне надсилання OTP/повідомлення згідно API.

---

### User Story 6 — Завершити або скасувати план лікування (Priority: P2)

Лікар на активному плані ініціює complete або cancel з причиною з довідника. **Complete** — без КЕП (API-007-005-0006), з write Approval і перевіркою activities. **Cancel** — за контрактом Cancel Care Plan (окремий API; якщо вимагає DS — підпис актуального знімка). Після успіху UI показує новий статус.

**Why this priority**: Закриття клінічного циклу; complete має жорсткі preconditions по activities.

**Independent Test**: Complete на плані з ≥1 completed activity і без scheduled/in-progress; cancel за окремим контрактом; після terminal status нові activities заборонені.

**Acceptance Scenarios**:

1. **Given** план `active`, усі activities у final статусі і ≥1 `completed`, **When** користувач завершує план через complete-модалку (причина з довідника, **без КЕП**) і має write Approval, **Then** статус `completed` у Системі й МІС.
2. **Given** план має activity `scheduled` або `in_progress`, **When** complete, **Then** Система/МІС блокує з повідомленням про незавершені призначення (409).
3. **Given** план `active`, **When** cancel через signature modal (КЕП) після Get Care Plan by ID + `care_plan_cancel_reasons`, **Then** статус відповідає моделі Системи і UI відображає причину.
4. **Given** помилка Системи, **When** дія не пройшла, **Then** статус плану не «застрягає» локально в суперечливому стані без пояснення.

---

### User Story 7 — Пошук, синхронізація та перегляд у контексті пацієнта (Priority: P3)

Лікар шукає плани пацієнта в МІС і підтягує з ЕСОЗ; бачить ідентифікатори ЕМЗ/плану; працює з merged persons за наявності доступу (п. 3.2.2.3.1 / 3.3.1.3.1).

**Why this priority**: Операційна зручність і цілісність даних після роботи в іншій МІС.

**Independent Test**: Sync списку планів пацієнта; пошук за UUID; повідомлення при 403 без consent.

**Acceptance Scenarios**:

1. **Given** пацієнт з планами в ЕСОЗ, **When** лікар робить sync, **Then** локальний список оновлюється (статуси, UUID, ключові поля).
2. **Given** немає доступу, **When** sync/detail, **Then** МІС показує потребу в Approval/декларації, а не «тиху» порожнечу.
3. **Given** merged persons, **When** лікар має доступ за п. 3.6.1.4.4, **Then** може знаходити пов’язані ЕМЗ/контекст для плану.

---

### Edge Cases

- Пацієнт без методів автентифікації — неможливо verify Approval / inform_with; МІС веде користувача створити/обрати метод (п. 3.7.1.5).
- Паралельна заявка/повторний create — відображення статусів CANCELLED/відхилених сутностей, заборона продовження з застарілого draft.
- Рецепт `intent=plan` — prequalify не застосовується; UI не пропонує dispense-сценарій.
- Device/service program participation не виконується — блокування до виправлення.
- Скасування пакету взаємодії з approval write (п. 3.2.2.8) не плутати зі скасуванням care plan — різні ресурси.
- Після complete/cancel плану — UI ховає create activity / issue document.
- Timeout / 202 Accepted jobs — polling з кінцевим успіхом/помилкою, без «вічного спінера».
- Sandbox OTP (`1234`) лише в non-production; prod — лише коди пацієнта.

---

## Requirements *(mandatory)*

### Functional Requirements

#### Передумови (з п. 3.2 / 3.3)

- **FR-001**: МІС MUST дозволяти роботу з планами лише користувачам, запис про яких відповідає вимогам активного працівника/ролі (п. 1.1.5 / п. 3.23–3.24).
- **FR-002**: Перед створенням плану МІС MUST забезпечити ідентифікованого пацієнта в реєстрі Системи (п. 3.7) і наявність finished Encounter з діагнозами, придатними стати `addresses` плану.
- **FR-003**: МІС MUST використовувати актуальні довідники Системи (п. 3.13.1) для категорій плану, причин cancel/complete, програм, послуг, відхилення рецептів тощо.
- **FR-004**: Для створення ЕН і пов’язаних сервісів МІС MUST обмежувати каталог послуг значеннями `request_allowed=true` / `is_active=true` відповідно до сценарію (п. 3.2.2.17–3.2.2.20, 3.3.1.22).

#### План лікування

- **FR-010**: Користувач `DOCTOR`/`SPECIALIST` MUST мати змогу створити чернетку плану локально і зареєструвати його в Системі з КЕП.
- **FR-011**: МІС MUST передавати обов’язкові реквізити плану згідно контракту Системи: пацієнт, автор (`employee_id`), умови надання (`terms_of_service` / PROVIDING_CONDITION), період, категорія, intent, addresses (діагнози encounter), supporting info за наявності.
- **FR-012**: Автор плану MUST мати активну роль, узгоджену з `terms_of_service` плану.
- **FR-013**: МІС MUST відображати статуси плану (`draft`/`new`/`active`/`on-hold`/`completed`/`revoked`/`entered-in-error`/…) і блокувати дії, недоступні для статусу.
- **FR-014**: МІС MUST зберігати UUID плану в контексті пацієнта і давати пошук/перегляд за ідентифікатором.
- **FR-015**: Редагування плану в МІС MUST бути дозволене лише у статусах, передбачених Системою (локально — доки статус `new`, якщо так визначено політикою доступу).
- **FR-016**: Complete плану MUST вимагати `status_reason` з `care_plan_complete_reasons`, write Approval (автор або працівник того ж managing_organisation з write), усі activities у final статусі й ≥1 `completed`; **без КЕП** (API-007-005-0006). Cancel плану MUST вимагати КЕП (API-007-005-0005), `status_reason` у signed content, збіг контенту з CBD без activities, лише **автор** з write Approval, усі activities final або відсутні.
- **FR-016b**: UI MUST розділяти Complete і Cancel: Complete — модалка причини/підтвердження без КЕП; Cancel — signature modal з КЕП після CBD Get.
- **FR-016c**: Кнопка Cancel плану MUST бути доступна лише автору плану (плюс write Approval). Complete MUST бути доступний автору або іншому працівнику того ж LE з write Approval на цей care_plan.
- **FR-017**: Поля, що є лише локальними (напр. клінічний протокол без підтримки в схемі CBD), MUST зберігатися локально і MUST NOT надсилатися в Систему як невідомі параметри.

#### Approvals (дозволи)

- **FR-020**: МІС MUST дозволяти створення Approval на ресурс типу `care_plan` для `granted_to` співробітника клінічного типу.
- **FR-021**: МІС MUST підтримувати асинхронне створення (job), верифікацію OTP/offline, resend, скасування Approval.
- **FR-022**: Рівень доступу `write` MUST вимагатися для створення/зміни activities і виписки документів **іншим** клініцистом того ж СГуСОЗ. Автор плану в тому ж LE MAY створювати activities без окремого Approval, доки ЕСОЗ приймає запит; при `403 Access denied` МІС MUST показати CTA на Approval.
- **FR-023**: МІС MUST ініціативно пояснювати `403 Access denied` як проблему згоди, з CTA на Approval.
- **FR-024**: Наявність активної декларації MAY допомагати доступу, але не замінює правило FR-022 для non-author; для автора діє FR-022 (implicit до 403).

#### Призначення (Activities)

- **FR-030**: МІС MUST підтримувати kinds: `service_request`, `medication_request`, `device_request`.
- **FR-031**: Створення activity в Системі MUST бути підписане КЕП і прив’язане до UUID плану.
- **FR-032**: МІС MUST контролювати статуси activity і залишки кількості/лімітів програми перед випискою документа.
- **FR-033**: Cancel/Complete activity MUST вимагати причини з довідників activity.

#### Електронні направлення (Service / Device Request)

- **FR-040**: Виписка ЕН MUST базуватися на зареєстрованій activity відповідного kind і `based_on` care_plan + activity.
- **FR-041**: Device (і service за наявності програми) MUST проходити prequalify до create, коли цього вимагає контракт Системи.
- **FR-042**: МІС MUST підтримувати sign, відображення статусу, resend повідомлень, cancel згідно статусної моделі Системи.
- **FR-043**: Для СМД МІС MUST підтримувати посилання на ЕН (`incoming_referral`) або блок `paper_referral` з обов’язковими полями (ЄДРПОУ, автор, дата) — п. 3.3.1.8.1 / 3.3.1.20.
- **FR-044**: Автор ЕН MUST мати змогу знайти ЕМЗ погашення направлення, якщо дані доступні (п. 3.2.2.15 / 3.3.1.19).

#### Електронні рецепти (Medication Request)

- **FR-050**: Для `intent=order` МІС MUST викликати PreQualify до Create MRR.
- **FR-051**: Create MRR MUST використовувати контрактний payload (ідентифікатори, період, medication, program, dosage, based_on care_plan+activity, context).
- **FR-052**: Sign MRR MUST створювати активний MR; МІС зберігає обидва ідентифікатори/статуси.
- **FR-053**: Відхилення NEW MRR і ACTIVE MR MUST йти через Reject API з довідником причин (не підміна довільним cancel без reject-контракту).
- **FR-054**: МІС MUST підтримувати inform_with / OTP resend / друковану форму за наявності в API.
- **FR-055**: МІС MUST валідувати route/dosage за довідниками Системи (SNOMED route тощо).

#### Спостережуваність і помилки

- **FR-060**: Усі помилки API Системи MUST показуватися користувачу; дані для повтору зберігаються в формі.
- **FR-061**: Асинхронні jobs MUST мати polling з термінальними станами success/failure.
- **FR-062**: МІС MUST НЕ передавати паролі/ключі КЕП у query string.

### Key Entities

- **Care Plan (План лікування)**: клінічний план пацієнта в ЕСОЗ; атрибути: UUID, статус, період, terms_of_service, категорія, intent, addresses, author employee, legal entity, encounter context.
- **Care Plan Activity (Призначення)**: елемент плану kind=service|medication|device; продукт/послуга, кількість, програма, статус, період.
- **Approval (Дозвіл/згода)**: згода пацієнта на доступ до ресурсу care_plan; granted_to employee, access_level, verification, authentication method.
- **Service Request / Device Request (ЕН)**: електронне направлення на послугу або виріб, засноване на activity.
- **Medication Request Request / Medication Request (е-рецепт)**: заявка до підпису (MRR) і підписаний рецепт (MR).
- **Encounter / Condition**: передумова — взаємодія та діагнози як addresses плану і context виписки.
- **Declaration**: альтернатива explicit Approval для implicit доступу лікаря ПМД.
- **Dictionary / Service Catalog / Medical Program**: нормативні обмеження кодів і програм.

---

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: Лікар з валідними передумовами завершує створення+підпис плану ≤ 5 хвилин (без урахування очікування OTP пацієнта).
- **SC-002**: 100% write-спроб без згоди отримують блокування з CTA на Approval (жоден «німий» 403 без пояснення в UI).
- **SC-003**: Успішна виписка ЕН і е-рецепта з підписаної activity проходить E2E на UAT без ручного виправлення payload.
- **SC-004**: PreQualify для medication (`intent=order`) і device виконується до create у 100% відповідних сценаріїв.
- **SC-005**: Reject MRR/MR покриває статусні переходи NEW→REJECTED і ACTIVE→REJECTED з довідником причин.
- **SC-006**: Complete плану проходить без КЕП при валідних activities; Cancel (якщо з DS) не дає «Signed content doesn't match» на синхронізованих даних.
- **SC-007**: Feature-тести домену Care Plan / Approvals / eRx / Referrals зелені в CI (`DB_DATABASE=testing`).
- **SC-008**: Користувач бачить і може скопіювати/знайти UUID плану та пов’язаних документів у UI пацієнта.

---

## Assumptions

- Вставлене ТЗ п. 3.2–3.3 задає **передумови ЕМЗ/ЕН/approvals**; окремий розділ ТЗ саме «Плани лікування» може бути доданий пізніше — ця специфікація фіксує цільову поведінку МІС на базі контрактів ЕСОЗ і поточного продукту.
- Ролі `DOCTOR` (ПМД) і `SPECIALIST` (СМД) — основні актори; ASSISTANT не є автором плану за замовчуванням.
- КЕП доступний користувачу в робочому місці МІС (або dummy sign лише в sandbox).
- Пацієнт має принаймні один метод автентифікації для Approval/OTP-сценаріїв.
- Каталог послуг і програми синхронізовані з Системою.
- Існуюча Laravel/Livewire реалізація — brownfield baseline; специфікація описує цільову поведінку (включно з gap-closure для eRx reject/prequalify).

## Out of Scope

- Повний модуль декларацій (3.2.1.*) і реорганізація СГуСОЗ.
- Ведення повного ЕМЗ (усі спостереження/вакцинації) — лише як передумова/контекст плану та погашення ЕН.
- Неідентифіковані пацієнти (3.3.3) як автори care plan.
- Окремий модуль медичних висновків (3.8).
- Зміна інфраструктури Sail/деплою.
