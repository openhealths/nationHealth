# ТВ39: скасування взаємодії та її записів

Issue #1206, частина umbrella #1201. База: upstream/main 3ae4e34e. Вимоги: тільки затверджена редакція 39.

| ID | Зміна | Перевірка |
|---|---|---|
| W01 | Перед підписанням читання detail взаємодії та всіх сторінок 11 наявних типів ЕМЗ; правильні encounter_id/encounter фільтри, UUID/context і сталі totals; діагнози з попередніх взаємодій читаються окремо. Синхронізація починається після успішного читання всього пакета. | Пагінація, дублікати, інший context, відсутній paging, зміна totals, помилка другої сторінки без часткового запису в БД. |
| W02 | Durable cancellation submission і блокування повторного запиту. Receipt/pending не скасовує записи; лише фінальний processed. Фонове та сторінкове завершення виконують одну транзакцію. Для втрати receipt повторне читання має підтвердити всі потрібні статуси. | Pending/failed/invalid job, whole/selected, Device Dispense, повторне завершення, refresh, worker completion між polls, uncertain queue exhaustion. |
| U01/U07 | viewCancellation зберігає перевірки scope, закладу та чинного автора після зміни статусу. ASSISTANT тільки власні записи. | Дозволений автор; сторонній, неактивний та інший заклад; assistant з додатковою посадою MED_ADMIN; denial без API/БД/queue effects. |
| U08 | Видиме session-flash повідомлення в реєстрах; modal лишається відкритою при field validation error; pending job відновлюється після відкриття сторінки. | Livewire consumer render, signing-field errors, terminal result один раз, compilation усіх 9 змінених Blade views. |

Контракти: офіційний локальний PREPROD Medical Events 9.15.6 (архів 22.10.2026), Cancel Encounter Package та Get Job Details. Розширення повної контрактної валідації пов'язаних EMЗ належить спільному G01 у umbrella; ця зміна викликає response validator перед споживанням.

Тести виконані ізольовано в tv39_1206; робочу mis_dev не мігрували. Додано сумісну install/update міграцію для відсутніх полів diagnostic_reports, потрібних навіть для транзакції скасування без diagnostic report. Rollback не видаляє клінічних даних.

Перевірки: 44 targeted tests; scoped Pint; git diff --check; 9 Blade views compiled and PHP linted. У ширшому Encounter suite виявлено наявні несумісні assertions для wire:click після переходу signature на wire:submit і прямий harness без Livewire computed lifecycle; передано власникам R/RX, інтегрований прогін у umbrella має включити їх виправлення.

Приймання ще потребує інтегрованого browser UAT і реального КЕП користувача. Pending, невизначений remote outcome або ще не виконаний UAT не позначаються як завершена перевірка в ЕСОЗ.
