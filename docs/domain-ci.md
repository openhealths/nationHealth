# Доменні тести в CI

Workflow `Domain tests` запускається на кожному PR у `main` та після push у `main`,
включно зі змінами лише залежностей, bootstrap або документації. Фільтрів `paths` немає,
щоб обов'язкова перевірка не залишалась у стані Pending на пропущених PR.

На PR стандартний `actions/checkout` перевіряє тимчасовий merge із базовою гілкою.
Кожен новий коміт у PR запускає перевірку повторно та скасовує попередній запуск.
GPT-рев'ю запускається окремо: його завершення не замінює успішні тести чи людський approval.

## Середовище і набір тестів

- PHP 8.5, залежності з `composer.lock`, локальний PostgreSQL на окремому hosted runner.
- БД має назву `testing`; скрипти підготовки та bootstrap тестів перевіряють цю назву.
- `scripts/ci/prepare-testing-db.sh` застосовує install і update/0_1 міграції.
- Завантаження ICD-10 через eHealth вимкнене лише під час підготовки схеми CI.
- `scripts/ci/run-domain-tests.sh` містить явний перелік 42 шляхів для Employee, Party,
  Care Plan / Activity, Medication Request та Referral. Це вибраний набір, не всі тести проєкту.
- JUnit-звіт публікується як artifact `phpunit-domain-junit`, якщо PHPUnit його створив.

Workflow має лише `contents: read`, без Azure/OIDC чи production secrets.
`scripts/ci/initialize-testing-env.sh` створює випадкові `DB_PASSWORD` і `APP_KEY`
для кожного запуску. GitHub маскує їх у логах і передає наступним крокам через
`GITHUB_ENV`; Azure Pipelines зберігає їх як змінні з `issecret=true`.
Значення не записуються у YAML чи Git. Параметри локальної БД також передаються
через змінні середовища; назва `testing` і loopback host забезпечують ізоляцію CI.
Скрипти підготовки не мають стандартного пароля й відмовляються працювати без нього.

Repository secrets для цього CI не потрібні: звичайні PR із форків їх не отримують.
Створення одноразових облікових даних дає змогу виконувати такі PR без доступу
до секретів команди. В Azure секретні змінні явно передаються потрібним крокам
через `env`. Azure bootstrap записує їх лише в локальний `.env` одноразового agent.

Моки eHealth дають змогу перевіряти локальну поведінку без preprod.
Вони не підтверджують сумісність із реальним API; для цього потрібен окремий integration/UAT прогін.

## Обов'язкові перевірки перед merge

Після успішного запуску та злиття цього workflow власник репозиторію має налаштувати
`Settings → Rules → Rulesets` для `main`:

1. Увімкнути `Require status checks to pass` і додати `Owned domain suites`
   з джерелом GitHub Actions.
2. Увімкнути `Require branches to be up to date before merging`.
3. Зберегти обов'язковий людський approval; для суттєвих нових змін після approval
   увімкнути його повторне отримання.

Ці правила зберігаються в налаштуваннях GitHub, YAML сам їх не створює.
Поки required checks не налаштовані, падіння тестів не є автоматичною забороною merge.

Якщо команда запровадить merge queue, потрібно також додати подію `merge_group`
до workflow перед увімкненням черги.

## Azure Pipelines і CD

`azure-pipelines.yml` використовує ті самі скрипти тестів і є альтернативою GitHub CI.
Його потрібно окремо зареєструвати та перевірити в Azure DevOps; зелений GitHub run
не підтверджує виконання Azure pipeline.

Розгортання, production міграції, staging approvals і rollback у цьому CI не налаштовані.
Це окремий етап CD.
