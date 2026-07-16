# OHealth / NationHealth Constitution

## Core Principles

### I. eHealth Compliance First
Усі функції МІС, що стосуються медичних даних пацієнта, планів лікування, направлень, рецептів і дозволів, MUST відповідати вимогам ЕСОЗ (API Системи, довідники, статусні моделі, scopes, ролі). Локальна логіка не може суперечити правилам Центральної БД. Помилки Системи MUST відображатися користувачу зрозумілою мовою з можливістю виправлення даних і повторного запиту.

### II. Consent Before Clinical Write
Доступ до захищених ресурсів пацієнта (ЕМЗ, план лікування, призначення) MUST базуватися на декларації АБО явному verified Approval з потрібним рівнем (`read` / `write`). Без згоди система MUST блокувати write-операції і пояснювати, як отримати доступ. OTP / offline-верифікація та повторне надсилання коду — обов’язкові сценарії.

### III. Sign What You Persist
Створення та зміна статусу ресурсів у ЕСОЗ, що вимагають КЕП (план, activity, направлення, рецепт), MUST підписувати саме той контент, який зафіксовано в Системі. Локальна БД MUST синхронізувати критичні поля (період, terms_of_service, статуси, UUID), щоб наступні підписи не розходилися з CBD.

### IV. Test-Proven Changes (NON-NEGOTIABLE)
Кожна зміна поведінки MUST мати автоматизований тест (Happy / Failure / Edge). Тести запускаються через Sail і NEVER торкаються БД `mis_dev`. Перед `artisan test` — `config:clear`. Регресії доменних flows (care plan, approval, eRx, referral) покриваються Feature-тестами.

### V. Brownfield Clarity & Minimal Diff
Проєкт — існуюча Laravel 12 / Livewire 3 МІС. Нові абстракції додаються лише коли існуючі сервіси/репозиторії не покривають контракт ЕСОЗ. Prefer розширення `ReferralRequestLifecycleService`-патерну для medication/device/service lifecycle замість дублювання в Livewire. UI українською, повідомлення користувачу — з доменної мови (не raw JSON).

## Domain Boundaries

- **In scope for Care Plans domain**: Care Plan, Care Plan Activity, Approval (consent), Service Request, Device Request, Medication Request / Medication Request Request, зв’язок з Encounter / діагнозами / каталогом послуг / програмами.
- **Preconditions (out of direct CRUD, but blocking)**: реєстр пацієнтів, декларації ПМД, ЕМЗ (епізоди/взаємодії), працівники/ролі, довідники.
- **Out of scope unless explicitly added**: повний модуль декларацій, MedData COVID mapping поза activity, медичні висновки, реорганізація закладу.

## Stack & Delivery Constraints

- PHP 8.5, Laravel 12, Livewire 3, Sail, PHPUnit, Pint.
- Команди лише через `vendor/bin/sail` / `make`.
- Feature branches лише в fork `Mefizz/ohealth` (`origin`); PR у `openhealths/nationHealth` `main`.
- Не зберігати секрети КЕП/OTP у URL чи логах.

## Governance

Ця constitution має пріоритет над локальними «швидкими фіксами», що ламають compliance. Зміни принципів — через оновлення цього файлу з bump версії. PRs у домені Care Plans MUST перевіряти: consent gates, signed content sync, статусні переходи ЕСОЗ, тести.

**Version**: 1.0.0 | **Ratified**: 2026-07-16 | **Last Amended**: 2026-07-16
