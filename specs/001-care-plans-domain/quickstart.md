# Quickstart: Care Plans Domain (local UAT)

## 0. Safety

```bash
vendor/bin/sail artisan config:clear
# Never RefreshDatabase against mis_dev
```

## 1. Login (PRIMARY_CARE)

- URL: `http://localhost/dev/login`
- Email: `openhealthkopylets@gmail.com`
- Заклад: **БЕЗШЕЙКО ВІТАЛІЙ ГРИГОРОВИЧ**
- Роль: **Лікар**
- Password: `8K6siHG0JL9cNby`

Secondary care (якщо потрібно): `openhealthkopylets+outp25@gmail.com` / `JJt12rDYsefu5Xf`

## 2. Patient

- Картка: `http://localhost/dashboard/1/persons/3`
- Плани: `http://localhost/dashboard/1/persons/3/care-plans`
- ПІБ: Пацієнт Якийсь, ДН 23.02.2001
- Активні плани для тестів: id 3, 4, 38

## 3. Happy path checklist

1. Переконатися, що є finished Encounter у ЕСОЗ з діагнозами.
2. Створити план лікування → підписати КЕП → статус `new`/`active`.
3. Якщо 403 на activity — створити Approval → OTP → verify.
4. Додати activity (service / medication / device) → підписати.
5. Виписати ЕН або е-рецепт → prequalify (де треба) → sign.
6. Complete або cancel плану з причиною.

## 4. Tests

```bash
vendor/bin/sail artisan config:clear
vendor/bin/sail artisan test --compact --filter=CarePlan
vendor/bin/sail artisan test --compact tests/Feature/CarePlan/CarePlanApprovalsTest.php
```

## 5. Spec Kit next commands

```text
/speckit-tasks
/speckit-analyze
/speckit-implement   # лише після пріоритизації tasks
```
