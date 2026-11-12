# Pipeline розробки та активація Azure

Базовий CI зі скриптами PR #940 вже є в `main`. Цей PR додає frontend build,
послідовний запуск reviewer та аналіз технічних вимог. До злиття workflow і trusted
scripts у `main` чинна автоматизація не змінюється.

## Послідовність PR

1. `Domain tests`: `Owned domain suites` (498 вибраних тестів на момент додавання)
   та незалежний `Frontend build` (`npm ci`, `npm run build`). Тестова БД локальна,
   пароль та APP_KEY одноразові. Цей набір не охоплює всі модулі застосунку.
2. Завершення CI викликає окремий `Azure review queue` через `workflow_run` на довіреному main. Gate перевіряє run
   через GitHub API: repository, workflow path, event, SHA, актуальну спробу й
   успішний результат обох обов'язкових jobs. Застарілий або невдалий CI не
   запускає модель. PR має бути ready, спрямований у main та від учасника команди.
3. Queue запускає `Azure OpenAI PR review` через `workflow_dispatch` на main;
   цей workflow виконує Azure OIDC login та наявне GPT code review.
4. Окремий `Technical requirements review` аналізує явно зазначені пункти ТВ.
   Якщо пунктів немає, check нейтральний, без запиту до моделі й зайвого коментаря.
5. Людина перевіряє результати, виконує UAT за потреби та надає approval.

Ручний запуск Azure review теж вимагає зеленого CI актуального commit; він не є
обходом тестів. Pipeline ніколи не checkout/execute код форку з Azure credentials,
не запускає Composer/npm у привілейованому job і не виконує PR artifacts.
Queue та model review мають окремі workflows з одним job у кожному, тому немає
постійно skipped job через взаємовиключні події. `pull_request_target` не використовується.

PR для завершеного CI знаходиться через пагінований список відкритих PR до main
з точним збігом head SHA, source repository та branch. Поля `run.pull_requests`
і commit→PR index GitHub можуть бути порожніми для форків; вони не є умовою запуску.
Перед dispatch PR перечитується і повторно перевіряється актуальний CI.
Журнал queue показує кількість збігів, dispatch або причину пропуску. Зелена queue
сама по собі не підтверджує, що модель завершила рев'ю: потрібен відповідний check.
Помилки запитів позначають сервіс (`GitHub`/`Azure OpenAI`), HTTP status та безпечний
код помилки, якщо він наданий. Текст запитів, токени та error body не логуються.

## Як зазначити ТВ

У title або body PR:

```text
ТВ: 3.5.1.3
Closes #123
```

Або `ТВ: 3.5`, `Requirements: 3.5.1.3, 3.7.1.3`. Номер читається з title/body
PR та пов'язаних issues. Підтримуються closing-links у GitHub Development,
`Closes/Fixes/Resolves #123` та явні `Refs #123` у body. Номер на початку title
також розпізнається (`#963: 3.20 medical devices`); довільні версії у тексті
не запускають audit. Найоднозначніший формат — `ТВ: номер`.

До п'яти issues і восьми незалежних заявлених пунктів/груп; вкладений пункт
не перевіряється повторно, якщо вже обрано його батьківську групу. Група `3.5`
автоматично ділиться на підрозділи з усіма дочірніми пунктами; великі підрозділи
діляться далі. Спільні умови батьків залишаються в контексті кожної частини.
Жодна частина не пропускається для отримання зеленого результату. Один вузький
пункт, наприклад `3.5.1.3`, перевіряється з усіма його підпунктами.

Зміна title/body PR запускає CI повторно. Зміна title/body issue автоматично
ставить її відкриті ready PR, прив'язані через GitHub closing-links, у чергу,
якщо CI поточного commit уже успішний. Якщо CI ще триває, запуск відбудеться
після його успішного завершення. Для лише текстового `Refs` без Development-link
після зміни issue потрібен ручний запуск reviewer. Ручний запуск також
дозволяє аналізувати старі відкриті PR з актуальним зеленим CI.

Для кожної частини модель бачить дерево допустимих PHP/Blade-файлів і вибирає
до шести релевантних файлів окремо, включно з наявною реалізацією поза diff PR.
Це не ліміт шість файлів на всю групу. Скрипт перевіряє вибір, читає
immutable base/head версії та передає
нумеровані рядки. Контекст обмежений 450 рядками/20 KB на версію файла; усі
скорочення позначені. Посилання у відповіді перевіряються проти цих рядків.
Позитивний висновок при скороченому контексті переводиться у `unknown`.
Однакові версії коду кешуються в межах запуску. Число модельних запитів і час
аналізу зростають із кількістю частин; таймаут або помилка не означають завершення.

Висновки: поведінка підтверджена наданим кодом, конкретна невідповідність або
потрібна додаткова перевірка. Звіт показує заявлений scope, кількість частин за
кожним статусом, конкретні підпункти, докази з рядками коду та наступний крок:
мінімальне виправлення/регресійний тест або відсутній контекст/сценарій UAT.
`covered` допускається лише для всіх умов частини; одна доведена невідповідність
дає `gap`, а непідтверджені умови — `unknown`.
У requirements audit можна повідомляти існуючі прогалини base; вплив PR
(`improved/regressed/unchanged`) вимагає посилань на обидві версії коду,
інакше він невідомий. Довгий звіт ділиться на коментарі з посиланнями;
позначка завершення публікується після всіх частин. Check засвідчує завершення
аналізу, а не автоматично блокує merge через AI-зауваження.

Результат прив'язаний до head/base, SHA corpus, версії алгоритму і переліку пунктів. Повторні запити
з тим самим fingerprint не витрачають токени. Застарілі результати не публікуються.
Джерело й оновлення: [корпус ТВ](technical-requirements/README.md).

## Налаштування GitHub після merge

Власник репозиторію в `Settings → Rules → Rulesets → main`:

- Додати required status checks `Owned domain suites`, `Frontend build`,
  `Azure OpenAI PR review`, `Technical requirements review`.
- Вимагати актуальну гілку та людський approval; нові зміни після approval мають
  вимагати повторної перевірки. Команді потрібно окремо погодити bypass policy.
- Обмежити зміну workflows, `.github/scripts`, CI-скриптів і corpus відповідальними
  reviewers через CODEOWNERS/правила. YAML сам не налаштовує ці права.
- Зберегти Azure variables чинного reviewer: `AZURE_CLIENT_ID`, `AZURE_TENANT_ID`,
  `AZURE_OPENAI_ENDPOINT`, `AZURE_OPENAI_DEPLOYMENT`. Довгостроковий ключ API не потрібен.

В Azure reviewer identity достатньо inference role на відповідному Foundry/OpenAI
ресурсі. Привілейований run залишається на main, тому existing branch FIC не
потрібно замінювати на subject форку або pull_request.

## Білінг

Для public repository стандартні GitHub-hosted runners безкоштовні; Azure credits
використовуються моделлю та погодженими Azure deployment resources. Перенесення
цього CI у платний Azure Pipelines не є умовою активації.

- [GitHub Actions billing](https://docs.github.com/en/billing/concepts/product-billing/github-actions).
- [Azure DevOps sponsorship billing FAQ](https://learn.microsoft.com/en-us/azure/devops/organizations/billing/billing-faq?view=azure-devops).

Не знімайте spending limit і не купуйте parallel jobs автоматично: умови
Sponsorship потрібно перевірити з власником перед платною активацією.

## Наступний крок CD

Розгортання хостингу наразі відкладене. CI та обидва reviewer можуть працювати
без staging, production credentials або ключів КЕП.

Deploy не активується лише від наявності Azure credits. До запуску потрібно
погодити конкретні staging/production targets і нинішній спосіб deploy:

- VM чи App Service/Container Apps, resource group, runtime, domain і health endpoint;
- окрема deploy identity, FIC під protected environment, мінімальні RBAC права;
- GitHub environments з approvals, обмеженням main та захистом production;
- незмінний release artifact із commit SHA, staging health check, promotion
  того самого artifact та rollback на попередню версію;
- чинні production APP_KEY/DB/eHealth secrets у сховищі середовища; без генерації
  нової APP_KEY або використання CI install/first-run на production;
- backup і окреме погодження production migrations (schema rollback не дорівнює
  rollback коду). Health check має підтвердити основні залежності.

У цьому PR підготовлено CI та два види рев'ю. Конкретний deploy driver й активація
CD залежать від підтвердження цілі власником; перевірки не є свідченням deploy.

## Тести підписання та живий КЕП

Автоматичні тести вже перевіряють підготовку payload, виклик сервісу підписання,
передачу підписаного контенту та відмову при відсутніх даних КЕП через mock
`SignatureProvider`. Вони не виконують криптографічне підписання реальним ключем.

Живий інтеграційний/UAT прогін потребує окремо погоджених:

- eHealth preprod endpoints, MIS/client налаштувань, callback URL і доступних
  тестових користувачів/закладів із потрібними ролями;
- сценаріїв і тестових даних, які можна створювати/змінювати у preprod;
- способу тестового підписання, доступного Cipher/test signer і участі власника КЕП.

Особисті ключі та паролі КЕП не передаються reviewer, не зберігаються у Git чи
GitHub artifacts. Для браузерного UAT власник підписує сам. Headless інтеграцію
можна обговорювати лише для спеціально призначених тестових облікових даних та
окремого захищеного workflow з довіреним кодом. Не можна виконувати довільний
код PR із доступом до signing secrets; особистий або production КЕП не використовується.
