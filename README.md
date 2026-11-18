# Nation Health
Health Information System (Медична інформаційна система)
## License & copyright
The software is distributed under [GPL version 3](https://www.gnu.org/licenses/gpl-3.0.en.html). Copyright &copy; 2026 Vitalii Bezsheiko
## Requirements
* PHP 8.5+
* PostgreSQL 17.5+
    - Other SQL databases also supported, full list at [Laravel Documentation](https://laravel.com/docs/13.x/database)
* Access to the [eHealth API](https://docs-preprod.ehealth.gov.ua/medical-events/description/introduction)
* [Composer](https://getcomposer.org), [npm](https://www.npmjs.com), [git](https://git-scm.com)
## Installation for development
### Git
```
git clone https://github.com/openhealths/nationHealth mis-dev
git clone  mis-dev
cd mis-dev
git checkout dev
composer install
npm install
npm run build
cp .env.example .env
```
### Environment variables
Before starting the application, see example of the [environment variables configuration](.env.example).
#### Application
```
APP_NAME=Laravel
APP_ENV=local
APP_KEY=
APP_DEBUG=true
APP_URL=http://localhost
```
General application related variables are defined in this block. `APP_KEY` should be defined only once with `php artisan key:generate`, it's used in data encryption and changing it would lead to inability to decode encrypted data.
#### Database
```
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=laravel
DB_USERNAME=root
DB_PASSWORD=
```
Database block. We recommend to use PostgreSQL `DB_CONNECTION=pgsql`. MySQL, SQLite, SQL Server, MariaDB are also supported.
#### Session
```
SESSION_DRIVER=database
SESSION_LIFETIME=120
SESSION_SECURE_COOKIE=false
```
`SESSION_SECURE_COOKIE` defaults to `true`, which marks the session cookie as `Secure`, so the browser only sends it back over HTTPS. Local development runs over plain `http://`, where Safari drops such a cookie entirely: every request arrives without a session and the first form submit fails with `419 Page Expired` (CSRF token mismatch). Set it to `false` in your local `.env` — other browsers still accept the cookie today, but nothing guarantees they will keep doing so. Keep it `true` on every HTTPS environment.
#### eHEALTH
```
EHEALTH_API_URL=https://api-preprod.ehealth.gov.ua
EHEALTH_AUTH_HOST=https://auth-preprod.ehealth.gov.ua
EHEALTH_REDIRECT_URI=
EHEALTH_OAUTH_REDIRECT_URI=
EHEALTH_X_CUSTOM_PSK=
EHEALTH_API_KEY=
```
`EHEALTH_API_URL` - URL to the eHealth API environment, `https://api-preprod.ehealth.gov.ua` is URL to the preproduction instance.

`EHEALTH_AUTH_HOST` - URL to the eHealth authentication API

`EHEALTH_REDIRECT_URI` - callback URL registered for the legal entity's connection with eHealth (client/consumer OAuth link). It must be a publicly accessible URL of your application; eHealth redirects to it after the legal entity connection flow.

`EHEALTH_OAUTH_REDIRECT_URI` - URL to the application endpoint, which handles user authentication. It receives authentication code from eHealth, which later is exchanged to the token. Contact eHealth to provide the redirect URL of the application.

`EHEALTH_X_CUSTOM_PSK` and `EHEALTH_API_KEY` - are provided by the eHealth and are used in the authentication process. For more details see eHealth [API documentation](https://uaehealthapi.docs.apiary.io/#reference/public.-medical-service-provider-integration-layer/oauth/login).
#### Other
```
CIPHER_API_URL=
```
Application supports qualified digital signatures and uses [Cipher](https://caas.cipher.com.ua) as a service provider. See, Cipher [cryptographic API documentation](https://docs.cipher.com.ua/display/CCSUOS)
## Deployment
```
php artisan first-run
```
This command:
* generates unique application API key (see API_KEY global variable),
* creates tables
* populates tables with data, such as roles and permissions

## What next?

First, start a development server:
```
php artisan serve --host=localhost
```
Successful start of the server will output something like:
```
 INFO  Server running on [http://localhost:8000].  

 Press Ctrl+C to stop the server
```
By default, the application doesn't have any registered legal entities (medical service providers).
To create the first legal entity, register a user with email and password on the registration page. Go to the registration page available at:
```
http://localhost:8000/register
```
The following page should appear:

![screenshot](docs/registration_page.png)

The next step is to fill the form and submit it. After that, on the specified email address, a confirmation email will be sent. Follow the link in the email to confirm the email address.
Note, that the mailer credentials should be configured properly in the `.env` file to enable email sending. E.g., if using [Mailtrap](https://mailtrap.io/) for development, the configuration may look like this:
```
MMAIL_MAILER=smtp
MAIL_HOST=sandbox.smtp.mailtrap.io
MAIL_PORT=2525
MAIL_USERNAME=at4a7769bcbfdf
MAIL_PASSWORD=********ff45
MAIL_ENCRYPTION=null
MAIL_FROM_ADDRESS="hello@example.com"
MAIL_FROM_NAME="${APP_NAME}"
```
Also, for local usage, [Sendria](https://pypi.org/project/sendria/) could be a great option for the mailer.

After following a link specified in the email, the user should be redirected to the login page (and the record in the column `email_verified_at` in the user table should change accordingly). The login page has the following URL:
```
http://localhost:8000/login
```
![screenshot](docs/login_page.png)

To register the first legal entity, check the box "Без авторизації у eHealth" ("Without eHealth authorization").
Then, enter credentials. If all is configured properly, after login, the user should be redirected to the dashboard page with the only option to register a legal entity:

![screenshot](docs/register_legal_entity.png)

There are several steps to register a legal entity, after filling all the data, the last step is to use a Qualified Digital Signature (QDS) to sign the data for eHealth.
Please use the valid QDS certificate of the legal entity or private entrepreneur, otherwise, the application and eHealth will reject the request.
When the registration is successful, which should be visible by the message on the screen, the user should log out. Before moving further, we recommend to configure asynchronous queues for synchronization of data with eHealth.

## Queues
### Command line
To start the queue worker, run the following command:
```
php artisan queue:work --queue=default,sync
```
After code changes, to apply them to the queue worker, restart it with:
```
php artisan queue:restart
```
See more details about queues in the [Laravel documentation](https://laravel.com/docs/13.x/queues#running-the-queue-worker).
### Supervisor
Another approach, which suits production environments better, is to use [Supervisor](http://supervisord.org/). Here is an example configuration file for Supervisor:
```
[program:oh]
process_name=%(program_name)s_%(process_num)02d
command=/opt/homebrew/bin/php /Users/myuser/mis/artisan queue:work --queue=default,sync --sleep=3 --tries=3 --max-time=3600
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=vitalii
numprocs=8
redirect_stderr=true
stdout_logfile=/Users/myuser/mis/storage/logs/worker.log
stopwaitsecs=3600
```
## Login Methods
After the initial setup and legal entity registration, you can access the application using one of the following methods:

### 1. Standard Login (via eHealth)
This is the official authentication flow used for production-like environments and integration testing.

1. Navigate to the login page: http://localhost:8000/login.
2. Ensure the checkbox "Без авторизації у eHealth" is unchecked.
 ![login_page.png](docs/login_page.png)
3. Select the registered legal entity in the corresponding field.
4. Click the login button. You will be redirected to the eHealth authentication page.
![ehealth_login_page.png](docs/ehealth_login_page.png)
5. Enter your credentials on the eHealth gateway.
6. After successful authentication, you will be redirected back to the dashboard.

### 2. Developer Login (via eHealth)
A specialized entry point for developers that follows the eHealth authentication flow but is optimized for local development and debugging.

![dev_login_page.png](docs/dev_login_page.png)

- URL: http://localhost:8000/dev/login

### 3. Local Login
This method is intended for local development to bypass external eHealth authentication.

![local_login_page.png](docs/local_login_page.png)

1. Navigate to the login page: http://localhost:8000/login.
2. Check the box "Без авторизації у eHealth".
3. Enter your local email and password.

> **Current Status:** This feature is in testing mode and is currently non-functional.
> The development of algorithms for full offline operation (without eHealth connectivity) is still in progress. Please use eHealth-based login methods for now.

After successful authentication, the user is automatically redirected to the application dashboard.

![dashboard_page.png](docs/dashboard_page.png)

> **Note:** For eHealth login to work properly, the `EHEALTH_REDIRECT_URI` in your `.env` must point to a real, accessible URL of your application. For local development, you may set `EHEALTH_CALLBACK_PROD=true` and configure `EHEALTH_URL_DEV` to `http://localhost:8000`.
## Code Style
See [docs/CODE_STYLE.md](docs/CODE_STYLE.md) for the project code style conventions.

## Local database tests

The testing setup and CI scripts check the original `APP_ENV`, the project's `.env`, and
cached application configuration before doing any work. If any declares `production`, they exit with a message before
creating a database, clearing caches, applying migrations, or running tests. A separate `.env.testing` does not bypass this check. If the environment cannot be determined, setup is also refused.

Tests support PostgreSQL, MySQL and MariaDB and require a database named `testing`.
The connection is selected exclusively from `.env.testing`, including in CI; exported
`DB_*` variables do not override it or fill in missing credentials. Each uncommented
`DB_CONNECTION` starts a block containing that connection's `DB_*` settings. The first
active block is used in file order. If several blocks are active, a message identifies
the selected driver without printing credentials. Commented blocks are ignored.
Child processes inherit the selection notice so PHPUnit's isolated tests do not
receive repeated messages on STDERR.
An absent or incomplete first block stops the command with an explanation; later
blocks are not used as a fallback and their settings are never mixed into the first.
Keep `DATABASE_URL` and `DB_URL` empty.

For MySQL or MariaDB, activate the corresponding commented example in `.env.testing`
after copying the template, provide its credentials, and create `testing` with an appropriate
owner before preparing the schema. `prepare-testing-db.php` runs the same install and
update commands for all three drivers and uses `psql` only for PostgreSQL.
For MariaDB, enable `explicit_defaults_for_timestamp=ON` in the server configuration
before preparing the schema. This is the default starting with MariaDB 10.10; older
versions such as 10.6 default to `OFF`, which causes `observations.issued` to fail with
`SQLSTATE 1067` under Laravel's strict SQL mode. See the
[MariaDB timestamp settings](https://mariadb.com/docs/server/server-management/variables-and-modes/server-system-variables#explicit_defaults_for_timestamp).
A supported connection does not guarantee that every domain test is portable.

Fresh request tables already use FHIR references. The FHIR conversion migration
validates that completed shape and performs no conversion on MySQL/MariaDB. Existing
legacy or incomplete request tables are refused before that migration changes schema
or reference data: their conversion still requires transactional DDL and a separate
MySQL/MariaDB migration strategy.

The PostgreSQL steps below assume the development application is already configured and `testing` does not exist.
Run all commands from the project root. `db_owner` is an example of an existing local
application database role; replace it with your role in both the command and `.env.testing`.

1. Check that the working `.env` contains `APP_ENV=local`, then verify the environment:

   ```sh
   php scripts/ci/assert-not-production.php
   ```

   Continue only if the check succeeds.

2. Configure `.env.testing`. If it already exists, keep it and check its settings. Otherwise,
   create it from the template:

   ```sh
   cp .env.testing.example .env.testing
   ```

   Set the connection values for your local PostgreSQL server, for example:

   ```dotenv
   APP_ENV=testing
   DB_CONNECTION=pgsql
   DB_HOST=127.0.0.1
   DB_PORT=5432
   DB_DATABASE=testing
   DB_USERNAME=db_owner
   DB_PASSWORD=
   DATABASE_URL=
   ```

   Fill in `DB_PASSWORD` with the password for that role, if required. Keep `DATABASE_URL`
   empty and retain the other testing settings from the template. `.env.testing` is gitignored
   and must not be committed.

   For a new file with an empty `APP_KEY`, generate a separate testing key:

   ```sh
   php scripts/dev/testing-artisan.php key:generate
   ```

3. Create the empty database with the same role as `DB_USERNAME`:

   ```sh
   php scripts/ci/assert-not-production.php &&
       sudo -u postgres createdb --owner=db_owner testing
   ```

   This administrative step is needed when the application role cannot create databases.
   Skip it if `testing` already exists.

   If `sudo` prompts, enter your operating system password. If PostgreSQL also prompts,
   enter the database password for the `postgres` role. `--owner` sets the owner of the
   new database. If another role administers your local cluster, connect with that role
   to create `testing` and assign ownership to the role in `DB_USERNAME`.

4. Prepare the database schema:

   ```sh
   php scripts/dev/prepare-testing-db.php
   ```

5. Verify that a database test runs successfully:

   ```sh
   ./vendor/bin/phpunit tests/Feature/SharedCodingPersistenceTest.php
   ```

If you delete only the `testing` database and keep the configured `.env.testing`, repeat
steps 3–5. You do not need to copy the template or generate a new key again.

The preparation command loads `.env.testing`, applies the project's install and update migrations, and skips the external ICD-10 download. Run it again after pulling new migrations;
it keeps the database and applies pending migrations. Test configuration uses a separate storage directory, including
Laravel caches, compiled views, logs, and Passport keys under `storage/cli-testing`. Tests use in-memory cache, sessions, and mail, a synchronous queue, and local files.
Each bootstrap invalidates its isolated configuration cache so a connection change in
`.env.testing` takes effect on the next invocation.
The PostgreSQL installer in `scripts/ci` is for hosted CI jobs only.
The CI environment initializer writes its generated credentials and key to a new
`.env.testing` with restricted permissions before Composer runs. It preserves an
existing `.env.testing`; the test bootstrap always uses the file's selected block.

Keep the PostgreSQL connection timezone consistent with the development database. On the same local server, a new database inherits the server timezone. The preparation command
leaves that setting in place; timezone-related test failures should remain visible.

Run all PHP tests from the `Unit` and `Feature` suites configured in `phpunit.xml`:

```sh
php scripts/dev/testing-artisan.php test
```

Alternatively, run the same suites directly with PHPUnit:

```sh
./vendor/bin/phpunit
```

The migration parity suite compares a fresh install with the complete update path,
including column types, nullability, defaults, comments, indexes, foreign keys, and CHECK
constraints. It also exercises legacy table shapes and repeated updates. Physical column
order and the migration history are excluded from the comparison: PostgreSQL appends
columns added by an update, and the two paths record different migration files.
This suite resets every table in the configured `testing` database and is excluded from
the normal suites. Select a disposable `testing` instance in `.env.testing` before running it:

```sh
MIS_TEST_MIGRATION_PARITY=1 ./vendor/bin/phpunit tests/Integration/Database/MigrationSchemaParityTest.php
```

To run only one module, pass its test directory. `Division` below is an example;
replace the path with the directory or individual test file you need:

```sh
./vendor/bin/phpunit tests/Feature/Division
```

To run the selected domain tests used by CI, use the project script. This runs the paths listed in `scripts/ci/run-domain-tests.sh`, rather than the complete PHP test suite:

```sh
bash scripts/ci/run-domain-tests.sh
```

The test bootstrap validates SQL connections created by Laravel's connection factory before opening PDO, including named connections, dynamically built connections, connection URLs, and read, write, and direct PostgreSQL targets. It permits only SQL databases named `testing` and SQLite `:memory:` fixtures. Additional test databases
require an explicit update to this guard. Tests writing through multiple connections must also include those names in `connectionsToTransact` when using Laravel's  `DatabaseTransactions` trait.
