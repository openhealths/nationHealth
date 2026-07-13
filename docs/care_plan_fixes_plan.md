# Аналіз помилок та план виправлення скасування Плану лікування (PR #355)

У цьому документі зібрано результати аналізу логів/БД та план дій для усунення помилок при скасуванні/завершенні планів лікування.

---

## 🔍 Результати аналізу помилок

### 1. Помилка `Signed content doesn't match with previously created care plan`
* **Причина**: При створенні плану лікування в ЕСОЗ дата початку автоматично коригується відповідно до часу початку взаємодії (Encounter start + 1 хв) — наприклад, `2026-06-22T07:47:00Z`.
* Однак, локальне збереження плану лікування (`CarePlanCreate::save` / `sign` та `CarePlanUpdate::save` / `sign`) **не синхронізує** зв'язок `effectivePeriod` (запис у таблиці `periods`).
* При скасуванні, оскільки `effectivePeriod` у БД відсутній, код вираховує дефолтну дату початку (Kyiv time midnight: `2026-06-22 00:00:00`, що конвертується в UTC як `2026-06-21T21:00:00Z`).
* Через цю розбіжність дат підписання на ЕСОЗ завершується помилкою валідації.

### 2. Помилка `$.terms_of_service.coding.[0].code: required property code was not present`
* **Причина**: При створенні/редагуванні плану лікування в локальній БД поле `terms_of_service` пропущене в масивах Eloquent `create`/`update`. Воно залишається `null` в базі даних.
* Під час скасування/завершення плану payload будується на основі значення з локальної БД. Оскільки там `null`, ЕСОЗ повертає помилку валідації про відсутність обов'язкового коду умов надання послуг.

### 3. Безпека: Витік паролів та ключів КЕП у GET-параметрах URL
* **Причина**: Форма скасування/завершення плану відправляється методом `GET`, через що пароль, КНЕДП та інші дані передаються відкритим текстом у параметрах запиту в URL: `/cancel?statusReason=...&password=...`.
* Також при GET-запитах файли (`keyContainerUpload`) не передаються на сервер, що робить підпис неможливим і ламає логіку зчитування ключа.

---

## 🛠️ Запропоновані зміни

### 1. Відмова від окремих роутів для скасування/завершення
* Видалити GET-роути для скасування та завершення з [web.php](file:///wsl.localhost/Ubuntu/home/mefizz/projects/ohealth/routes/web.php).
* Видалити класи Livewire-компонентів:
  * `App\Livewire\CarePlan\Cancel\CarePlanCancel`
  * `App\Livewire\CarePlan\Complete\CarePlanComplete`
* Видалити відповідні шаблони Blade:
  * `resources/views/livewire/care-plan/cancel/care-plan-cancel.blade.php`
  * `resources/views/livewire/care-plan/complete/care-plan-complete.blade.php`

### 2. Інтеграція підпису безпосередньо у сторінку деталей
* На сторінці деталей плану лікування (`/care-plans/{carePlan}`) змінити посилання "Скасувати" та "Завершити" на кнопки, що безпосередньо викликають `wire:click="openSignatureModal('cancel')"` та `wire:click="openSignatureModal('complete')`.
* Оскільки клас `CarePlanShow` уже використовує трейт `ManagesCarePlanLifecycle`, модальні вікна підпису та логіка відправки на ЕСОЗ будуть працювати інлайново на тій самій сторінці.

### 3. Запобігання GET-відправці при натисканні Enter
* У компоненті [signature-modal.blade.php](file:///wsl.localhost/Ubuntu/home/mefizz/projects/ohealth/resources/views/components/signature-modal.blade.php) змінити `<form>` на `<form onsubmit="return false;">`. Це вимкне стандартну поведінку браузера з відправки форми через GET при натисканні клавіші Enter у полі введення пароля.

### 4. Виправлення синхронізації БД (Period та Terms of Service)
* Додати `'terms_of_service' => $this->form->termsOfService` до Eloquent-масивів збереження та оновлення в:
  * `CarePlanCreate::save()`
  * `CarePlanCreate::sign()`
  * `CarePlanUpdate::save()`
  * `CarePlanUpdate::sign()`
* Синхронізувати відношення `effectivePeriod` після збереження/оновлення локального запису плану лікування за допомогою:
  ```php
  \App\Repositories\MedicalEvents\Repository::period()->sync($carePlan, $entity['period'] ?? ($finalResponse['period'] ?? []), 'effectivePeriod');
  ```
  (або використовуючи київський час для локальних чернеток).

---

## 🧪 План верифікації

1. **Автоматичні тести**:
   * Запустити тести планів лікування: `vendor/bin/sail artisan test --compact --filter=CarePlan`
2. **Ручне тестування**:
   * Створити новий план лікування.
   * Спробувати скасувати його безпосередньо на сторінці деталей.
   * Перевірити, що модальне вікно скасування відкривається поверх сторінки деталей, роут не змінюється, а введення пароля та натискання Enter не призводить до перезавантаження чи витоку даних.
