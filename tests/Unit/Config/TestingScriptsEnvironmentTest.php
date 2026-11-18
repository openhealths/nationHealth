<?php

declare(strict_types=1);

namespace Tests\Unit\Config;

use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

class TestingScriptsEnvironmentTest extends TestCase
{
    protected string $fixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixture = sys_get_temp_dir().'/mis-script-guard-'.bin2hex(random_bytes(8));
        mkdir($this->fixture, 0700);
        $project = dirname(__DIR__, 3);
        foreach (array_merge(self::scripts(), [
            ['scripts/ci/assert-not-production.php'],
            ['tests/bootstrap.php'],
            ['scripts/ci/assert-not-production.sh'],
        ]) as [$file]) {
            $this->write($file, file_get_contents($project.'/'.$file));
        }
        $this->write('.env.testing.example', file_get_contents($project.'/.env.testing.example'));
        $this->write('.env', "APP_ENV=local\n");
        $this->write('.env.testing', "APP_ENV=testing\nDB_CONNECTION=pgsql\nDB_HOST=127.0.0.1\nDB_PORT=5432\nDB_DATABASE=testing\nDB_USERNAME=fixture\nDB_PASSWORD=fixture\nAPP_KEY=fixture\n");
        $autoload = var_export($project.'/vendor/autoload.php', true);
        $this->write('vendor/autoload.php', "<?php require $autoload;");
        $this->write('vendor/composer/installed.json', '{"packages":[]}');
        $this->write('composer.json', '{}');

        // These commands can only record a failure; the tests never install or contact a database.
        $marker = escapeshellarg($this->fixture.'/command-ran');
        foreach (['sudo', 'psql', 'pg_isready', 'openssl'] as $command) {
            $this->write('bin/'.$command, "#!/bin/sh\nprintf '%s' '$command' > $marker\nexit 91\n");
            chmod($this->fixture.'/bin/'.$command, 0755);
        }
        $guard = escapeshellarg($this->fixture.'/scripts/ci/assert-not-production.php');
        $php = escapeshellarg(PHP_BINARY);
        $this->write('bin/php', "#!/bin/sh\nif [ \"\$1\" = $guard ]; then exec $php \"\$@\"; fi\nprintf php > $marker\nexit 91\n");
        chmod($this->fixture.'/bin/php', 0755);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->deleteDirectory($this->fixture);
        parent::tearDown();
    }

    protected function write(string $file, string $contents): void
    {
        $path = $this->fixture.'/'.$file;
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0700, true);
        }
        file_put_contents($path, $contents);
    }

    protected function runScript(string $script, array $environment = [], array $arguments = []): Process
    {
        $process = new Process([
            str_ends_with($script, '.php') ? PHP_BINARY : '/bin/bash',
            $this->fixture.'/'.$script,
            ...$arguments,
        ], $this->fixture, array_replace([
            'APP_ENV' => 'testing',
            'APP_CONFIG_CACHE' => false,
            'DB_CONNECTION' => 'pgsql',
            'DB_HOST' => '127.0.0.1',
            'DB_DATABASE' => 'testing',
            'DB_USERNAME' => 'fixture',
            'DB_PASSWORD' => 'fixture',
            'GITHUB_ACTIONS' => 'true',
            'GITHUB_ENV' => $this->fixture.'/github-env',
            'PATH' => $this->fixture.'/bin:'.getenv('PATH'),
        ], $environment));
        $process->setTimeout(15);
        $process->run();

        return $process;
    }

    protected function readTestingEnvironment(array $environment = []): Process
    {
        $this->write('read-environment.php', <<<'PHP'
<?php
require __DIR__.'/tests/bootstrap.php';
$keys = ['DB_CONNECTION', 'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD', 'DB_SOCKET', 'MYSQL_ATTR_SSL_CA', 'APP_KEY'];
echo json_encode(array_combine($keys, array_map(getenv(...), $keys)), JSON_THROW_ON_ERROR);
PHP);

        return $this->runScript('read-environment.php', $environment);
    }

    protected function useRealApplication(): void
    {
        $project = dirname(__DIR__, 3);
        (new Filesystem())->deleteDirectory($this->fixture.'/vendor');
        foreach (['vendor', 'config', 'routes', 'app', 'resources'] as $directory) {
            symlink($project.'/'.$directory, $this->fixture.'/'.$directory);
        }
        foreach (['bootstrap/app.php', 'tests/application.php', 'composer.json'] as $file) {
            $this->write($file, file_get_contents($project.'/'.$file));
        }
    }

    #[TestWith(['pgsql', '5432'])]
    #[TestWith(['mysql', '3306'])]
    #[TestWith(['mariadb', '3306'])]
    public function test_testing_file_owns_connection_settings_even_in_ci(string $driver, string $port): void
    {
        $this->write('.env.testing', "APP_KEY=file-key\nDB_CONNECTION=$driver\nDB_HOST=file-host\nDB_PORT=$port\nDB_DATABASE=testing\nDB_USERNAME=file-user\nDB_PASSWORD=file-password\n");

        $process = $this->readTestingEnvironment([
            'DB_CONNECTION' => 'sqlsrv', 'DB_HOST' => 'inherited-host', 'DB_PORT' => '1',
            'DB_USERNAME' => 'inherited-user', 'DB_PASSWORD' => 'inherited-password',
            'DB_SOCKET' => '/inherited.sock', 'MYSQL_ATTR_SSL_CA' => '/inherited.pem', 'APP_KEY' => 'inherited-key',
        ]);

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $this->assertSame([
            'DB_CONNECTION' => $driver, 'DB_HOST' => 'file-host', 'DB_PORT' => $port,
            'DB_DATABASE' => 'testing', 'DB_USERNAME' => 'file-user', 'DB_PASSWORD' => 'file-password',
            'DB_SOCKET' => '', 'MYSQL_ATTR_SSL_CA' => '', 'APP_KEY' => 'file-key',
        ], json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR));
    }

    #[TestWith(['pgsql', 'mysql'])]
    #[TestWith(['mysql', 'mariadb'])]
    #[TestWith(['mariadb', 'pgsql'])]
    public function test_first_active_block_wins_without_inheriting_later_credentials(string $first, string $second): void
    {
        $this->write('.env.testing', <<<ENV
APP_KEY=fixture
# DB_CONNECTION=sqlsrv
DB_CONNECTION=$first
DB_HOST=first-host
DB_PORT=1234
DB_DATABASE=testing
DB_USERNAME=first-user

DB_CONNECTION=$second
DB_HOST=second-host
DB_PORT=4321
DB_DATABASE=application_live
DB_USERNAME=second-user
DB_PASSWORD=later-secret
DB_SOCKET=/later.sock
MYSQL_ATTR_SSL_CA=/later.pem
ENV);

        $process = $this->readTestingEnvironment();

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $settings = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($first, $settings['DB_CONNECTION']);
        $this->assertSame('first-host', $settings['DB_HOST']);
        $this->assertSame('1234', $settings['DB_PORT']);
        $this->assertSame('testing', $settings['DB_DATABASE']);
        $this->assertSame('first-user', $settings['DB_USERNAME']);
        foreach (['DB_PASSWORD', 'DB_SOCKET', 'MYSQL_ATTR_SSL_CA'] as $key) {
            $this->assertSame('', $settings[$key]);
        }
        $this->assertStringContainsString("Found 2 active database blocks in .env.testing; using the first [$first, database testing]", $process->getErrorOutput());
        $this->assertStringNotContainsString('later-secret', $process->getErrorOutput());
    }

    public function test_commented_blocks_and_multiline_values_are_not_connection_headers(): void
    {
        $this->write('.env.testing', <<<'ENV'
# DB_CONNECTION=mysql
# DB_DATABASE=application_live
TEST_HOST=selected-host
DB_CONNECTION=pgsql
DB_HOST="${TEST_HOST}"
DB_PORT=5432
DB_DATABASE=testing
DB_USERNAME=fixture
DB_PASSWORD="first line
DB_CONNECTION=mariadb
last line"
ENV);
        $process = $this->readTestingEnvironment();

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $settings = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('selected-host', $settings['DB_HOST']);
        $this->assertSame("first line\nDB_CONNECTION=mariadb\nlast line", $settings['DB_PASSWORD']);
        $this->assertSame('', $process->getErrorOutput());
    }

    #[TestWith(['pgsql'])]
    #[TestWith(['mysql'])]
    #[TestWith(['mariadb'])]
    public function test_multiple_blocks_do_not_break_phpunit_process_isolation(string $driver): void
    {
        $settings = str_replace('DB_CONNECTION=pgsql', 'DB_CONNECTION='.$driver, file_get_contents($this->fixture.'/.env.testing'));
        $this->write('.env.testing', $settings."\nDB_CONNECTION=mysql\nDB_DATABASE=application_live\n");
        $this->write('DatabaseNoticeTest.php', str_replace('__DRIVER__', $driver, <<<'PHP'
<?php
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

class DatabaseNoticeTest extends TestCase
{
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_selected_connection_is_preserved(): void
    {
        $this->assertSame('__DRIVER__', getenv('DB_CONNECTION'));
        $this->assertSame('testing', getenv('DB_DATABASE'));
    }
}
PHP));
        $process = new Process([
            PHP_BINARY, dirname(__DIR__, 3).'/vendor/bin/phpunit', '--no-configuration',
            '--bootstrap', $this->fixture.'/tests/bootstrap.php', '--colors=never',
            $this->fixture.'/DatabaseNoticeTest.php',
        ], $this->fixture, ['APP_ENV' => 'testing', 'APP_CONFIG_CACHE' => false]);
        $process->setTimeout(15);
        $process->run();

        $this->assertSame(0, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
        $this->assertSame(1, substr_count($process->getErrorOutput(), 'Found 2 active database blocks'));
        $this->assertStringContainsString('OK (1 test, 2 assertions)', $process->getOutput());
    }

    #[TestWith([''])]
    #[TestWith(["# DB_CONNECTION=pgsql\n# DB_DATABASE=testing\n"])]
    public function test_missing_active_connection_has_a_clear_error(string $contents): void
    {
        $this->write('.env.testing', $contents);

        $process = $this->readTestingEnvironment();

        $this->assertNotSame(0, $process->getExitCode());
        $this->assertStringContainsString('No active database connection in .env.testing', $process->getErrorOutput());
        $this->assertDirectoryDoesNotExist($this->fixture.'/storage');
    }

    #[TestWith(['DB_HOST'])]
    #[TestWith(['DB_PORT'])]
    #[TestWith(['DB_USERNAME'])]
    public function test_incomplete_first_block_does_not_fall_back_to_ci_or_another_block(string $missing): void
    {
        $settings = file_get_contents($this->fixture.'/.env.testing');
        $first = preg_replace('/^'.$missing.'=.*\n/m', '', $settings);
        $this->write('.env.testing', $first."\n".$settings);

        $process = $this->readTestingEnvironment();

        $this->assertNotSame(0, $process->getExitCode());
        $this->assertStringContainsString("Missing testing connection setting [$missing]", $process->getErrorOutput());
        $this->assertDirectoryDoesNotExist($this->fixture.'/storage');
    }

    #[TestWith(['DB_DATABASE=application_live'])]
    #[TestWith(['DATABASE_URL=mysql://localhost/application_live'])]
    #[TestWith(['DB_URL=postgresql://localhost/application_live'])]
    #[TestWith(['DB_CONNECTION=sqlite'])]
    public function test_unsafe_or_unsupported_first_block_is_rejected(string $replacement): void
    {
        $name = explode('=', $replacement, 2)[0];
        $settings = file_get_contents($this->fixture.'/.env.testing');
        $settings = preg_replace('/^'.$name.'=.*$/m', $replacement, $settings, count: $count);
        $this->write('.env.testing', $settings.($count === 0 ? $replacement."\n" : ''));

        $process = $this->readTestingEnvironment();

        $this->assertNotSame(0, $process->getExitCode());
        $this->assertDirectoryDoesNotExist($this->fixture.'/storage');
    }

    public static function scripts(): array
    {
        return [
            'testing Artisan' => ['scripts/dev/testing-artisan.php'],
            'local setup' => ['scripts/dev/prepare-testing-db.php'],
            'schema setup' => ['scripts/ci/prepare-testing-db.sh'],
            'PostgreSQL installation' => ['scripts/ci/install-postgres.sh'],
            'domain tests' => ['scripts/ci/run-domain-tests.sh'],
            'CI credentials' => ['scripts/ci/initialize-testing-env.sh'],
        ];
    }

    public static function productionContexts(): array
    {
        $cases = [];
        foreach (self::scripts() as $name => [$file]) {
            foreach (['process', 'dotenv', 'cache'] as $source) {
                $cases[$name.' / '.$source] = [$file, $source];
            }
        }

        return $cases;
    }

    #[DataProvider('productionContexts')]
    public function test_production_stops_before_any_work(string $script, string $source): void
    {
        if ($source === 'dotenv') {
            $this->write('.env', "\xEF\xBB\xBFexport \"APP_ENV\"=\"production\" # deployment\n");
        } elseif ($source === 'cache') {
            $this->write('bootstrap/cache/config.php', "<?php return ['app' => ['env' => 'production']];");
        }
        $process = $this->runScript($script, ['APP_ENV' => $source === 'process' ? 'production' : 'testing']);

        $this->assertSame(1, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
        $this->assertStringContainsString('cannot run in production', $process->getErrorOutput());
        $this->assertFileDoesNotExist($this->fixture.'/command-ran');
        $this->assertFileDoesNotExist($this->fixture.'/github-env');
        $this->assertDirectoryDoesNotExist($this->fixture.'/storage/ci');
    }

    public function test_installer_rejects_a_local_run_and_a_foreign_ci_role_before_sudo(): void
    {
        foreach ([
            ['GITHUB_ACTIONS' => false, 'TF_BUILD' => false, 'DB_USERNAME' => 'ci_test'],
            ['GITHUB_ACTIONS' => 'true', 'DB_USERNAME' => 'db_owner'],
        ] as $environment) {
            $process = $this->runScript('scripts/ci/install-postgres.sh', $environment);
            $this->assertSame(1, $process->getExitCode());
            $this->assertFileDoesNotExist($this->fixture.'/command-ran');
        }
    }

    public function test_installer_reuses_an_existing_ci_role_without_changing_it(): void
    {
        // Permit the two catalog reads and login. Any other administrator command fails.
        $this->write('bin/pg_isready', "#!/bin/sh\nexit 0\n");
        $this->write('bin/psql', "#!/bin/sh\nexit 0\n");
        $this->write('bin/sudo', <<<'SH'
#!/bin/sh
case "$*" in
    *"SELECT pg_get_userbyid("*|*"SELECT rolname FROM pg_roles"*) echo ci_test ;;
    *) exit 91 ;;
esac
SH);
        $process = $this->runScript('scripts/ci/install-postgres.sh', [
            'DB_HOST' => '127.0.0.1',
            'DB_PORT' => '5432',
            'DB_USERNAME' => 'ci_test',
            'DB_PASSWORD' => str_repeat('a', 64),
        ]);
        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
    }

    public static function databaseStates(): array
    {
        return [
            'accessible without postgres privileges' => ['ready', ['probe']],
            'needs creation' => ['missing', ['probe', 'catalog', 'create']],
        ];
    }

    #[TestWith(['APP_KEY'])]
    #[TestWith(['APP_KEY='])]
    public function test_setup_requires_a_testing_key_before_contacting_the_database(string $assignment): void
    {
        $bootstrap = file_get_contents($this->fixture.'/tests/bootstrap.php');
        $this->write('tests/bootstrap.php', $bootstrap.'putenv('.var_export($assignment, true).');'."\n");

        $process = $this->runScript('scripts/dev/prepare-testing-db.php', ['DB_PORT' => '5432']);

        $this->assertSame(1, $process->getExitCode());
        $this->assertStringContainsString('Generate a testing key first: php scripts/dev/testing-artisan.php key:generate', $process->getErrorOutput());
        $this->assertFileDoesNotExist($this->fixture.'/command-ran');
    }

    #[DataProvider('databaseStates')]
    public function test_setup_connects_to_testing_before_creating_it(string $state, array $expectedCalls): void
    {
        $this->write('scripts/dev/testing-artisan.php', '<?php echo $argv[1], PHP_EOL;');
        $this->write('bin/psql', <<<'SH'
#!/bin/sh
case "$*" in
    *"-d testing "*"SELECT 1") echo probe >> calls; [ "$FIXTURE_DB_STATE" = ready ] ;;
    *"-d postgres "*"FROM pg_database"*) echo catalog >> calls; [ "$FIXTURE_DB_STATE" = missing ] ;;
    *"-d postgres "*"CREATE DATABASE testing") echo create >> calls; [ "$FIXTURE_DB_STATE" = missing ] ;;
    *) exit 91 ;;
esac
SH);
        $process = $this->runScript('scripts/dev/prepare-testing-db.php', [
            'DB_HOST' => '127.0.0.1',
            'DB_PORT' => '5432',
            'APP_KEY' => 'fixture',
            'FIXTURE_DB_STATE' => $state,
        ]);

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $this->assertSame($expectedCalls, file($this->fixture.'/calls', FILE_IGNORE_NEW_LINES));
        $this->assertStringContainsString("config:clear\nmigrate:install\ninstall\nupdate\n", $process->getOutput());
    }

    public function test_setup_preserves_the_working_cache_and_writes_the_key_only_to_testing(): void
    {
        $project = dirname(__DIR__, 3);
        $this->useRealApplication();
        $this->write('.env.testing', file_get_contents($project.'/.env.testing.example'));
        $cache = "<?php return ['app' => ['env' => 'local']];";
        $this->write('bootstrap/cache/config.php', $cache);

        foreach (['config:clear', 'key:generate'] as $command) {
            $process = new Process([PHP_BINARY, 'scripts/dev/testing-artisan.php', $command], $this->fixture, [
                'APP_CONFIG_CACHE' => 'bootstrap/cache/config.php',
                'GITHUB_ACTIONS' => false,
                'TF_BUILD' => false,
            ]);
            $process->mustRun();
        }
        $this->assertSame($cache, file_get_contents($this->fixture.'/bootstrap/cache/config.php'));
        $this->assertSame("APP_ENV=local\n", file_get_contents($this->fixture.'/.env'));
        $this->assertMatchesRegularExpression('/^APP_KEY=base64:.+/m', file_get_contents($this->fixture.'/.env.testing'));
        $this->assertFileExists($this->fixture.'/storage/cli-testing/bootstrap/cache/services.php');
        $this->assertFileDoesNotExist($this->fixture.'/bootstrap/cache/services.php');
    }

    public function test_laravel_uses_the_selected_mariadb_block_despite_stale_testing_configuration(): void
    {
        $this->useRealApplication();
        $settings = str_replace('DB_CONNECTION=pgsql', 'DB_CONNECTION=mariadb', file_get_contents($this->fixture.'/.env.testing'));
        $settings = str_replace("DB_PASSWORD=fixture\n", '', $settings);
        $this->write('.env.testing', $settings."\nDB_CONNECTION=mysql\nDB_DATABASE=application_live\nDB_PASSWORD=later-secret\nDB_SOCKET=/later.sock\n");
        $this->write('read-application.php', <<<'PHP'
<?php
require __DIR__.'/tests/bootstrap.php';
$app = require __DIR__.'/tests/application.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (($argv[1] ?? null) === 'cache') {
    $app['config']->set('database.default', 'pgsql');
    file_put_contents($app->getCachedConfigPath(), '<?php return '.var_export($app['config']->all(), true).';');
    exit(0);
}
$connection = $app['db']->connection();
echo json_encode([
    'class' => $connection::class,
    'driver' => $connection->getDriverName(),
    'database' => $connection->getDatabaseName(),
    'password' => $connection->getConfig('password'),
    'socket' => $connection->getConfig('unix_socket'),
], JSON_THROW_ON_ERROR);
PHP);

        $cache = $this->runScript('read-application.php', [], ['cache']);
        $this->assertSame(0, $cache->getExitCode(), $cache->getErrorOutput());
        $this->assertFileExists($this->fixture.'/storage/cli-testing/bootstrap/cache/config.php');
        $process = $this->runScript('read-application.php');

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $this->assertSame([
            'class' => \Illuminate\Database\MariaDbConnection::class,
            'driver' => 'mariadb', 'database' => 'testing', 'password' => '', 'socket' => '',
        ], json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR));
    }

    #[TestWith(['mysql'])]
    #[TestWith(['mariadb'])]
    public function test_mysql_setup_uses_the_file_and_never_invokes_psql(string $driver): void
    {
        $this->write('.env.testing', str_replace('DB_CONNECTION=pgsql', 'DB_CONNECTION='.$driver, file_get_contents($this->fixture.'/.env.testing')));
        $this->write('scripts/dev/testing-artisan.php', '<?php echo $argv[1], PHP_EOL;');
        $this->write('bin/php', '#!/bin/sh'."\n".'exec '.escapeshellarg(PHP_BINARY).' "$@"'."\n");

        $process = $this->runScript('scripts/ci/prepare-testing-db.sh', [
            'DB_CONNECTION' => 'sqlsrv', 'DB_DATABASE' => 'application_live',
        ]);

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $this->assertStringContainsString("config:clear\nmigrate:install\ninstall\nupdate\n", $process->getOutput());
        $this->assertStringContainsString("Testing DB ready: $driver / testing", $process->getOutput());
        $this->assertFileDoesNotExist($this->fixture.'/command-ran');
    }

    #[TestWith(['bootstrap'])]
    #[TestWith(['repository'])]
    public function test_testing_artisan_returns_failure_when_preflight_throws_with_laravel_handlers_installed(string $stage): void
    {
        $this->useRealApplication();
        $hook = $stage === 'bootstrap' ? <<<'PHP'
$app->booting(static function (): void {
    throw new RuntimeException('Testing bootstrap is unavailable.');
});
PHP : <<<'PHP'
$app->extend('migrator', static function () {
    $migrator = Mockery::mock(Illuminate\Database\Migrations\Migrator::class);
    $migrator->shouldReceive('repositoryExists')->once()->andThrow(new RuntimeException('Testing repository is unavailable.'));

    return $migrator;
});
PHP;
        $this->write('tests/application.php', str_replace('return $app;', $hook."\nreturn \$app;", file_get_contents($this->fixture.'/tests/application.php')));

        $process = $this->runScript('scripts/dev/testing-artisan.php', [], ['migrate:install']);

        $this->assertSame(1, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
        $this->assertStringContainsString("Testing command failed: Testing $stage is unavailable.", $process->getErrorOutput());
    }

    #[TestWith(['github'])]
    #[TestWith(['azure'])]
    public function test_ci_initializer_writes_job_credentials_to_a_new_testing_file(string $provider): void
    {
        unlink($this->fixture.'/.env.testing');
        $this->fakeCredentialGenerator();

        $process = $this->runScript('scripts/ci/initialize-testing-env.sh', [
            'GITHUB_ACTIONS' => $provider === 'github' ? 'true' : false,
            'TF_BUILD' => $provider === 'azure' ? 'True' : false,
        ]);

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $this->assertSame(0600, fileperms($this->fixture.'/.env.testing') & 0777);
        $read = $this->readTestingEnvironment();
        $this->assertSame(0, $read->getExitCode(), $read->getErrorOutput());
        $settings = json_decode($read->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('pgsql', $settings['DB_CONNECTION']);
        $this->assertSame('ci_test', $settings['DB_USERNAME']);
        $this->assertSame(str_repeat('a', 64), $settings['DB_PASSWORD']);
        $this->assertSame('base64:'.base64_encode(str_repeat('k', 32)), $settings['APP_KEY']);
    }

    public function test_ci_initializer_preserves_an_existing_testing_file(): void
    {
        $contents = file_get_contents($this->fixture.'/.env.testing');
        $this->fakeCredentialGenerator();

        $process = $this->runScript('scripts/ci/initialize-testing-env.sh');

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $this->assertSame($contents, file_get_contents($this->fixture.'/.env.testing'));
    }

    protected function fakeCredentialGenerator(): void
    {
        $password = str_repeat('a', 64);
        $key = base64_encode(str_repeat('k', 32));
        $this->write('bin/openssl', "#!/bin/sh\ncase \"\$*\" in\n 'rand -hex 32') printf '%s' '$password' ;;\n 'rand -base64 32') printf '%s' '$key' ;;\n *) exit 91 ;;\nesac\n");
    }

    #[TestWith(['0', "migrate:install\n"])]
    #[TestWith(['1', ''])]
    public function test_testing_artisan_only_installs_a_missing_migration_repository(string $repositoryExists, string $expectedOutput): void
    {
        $this->write('tests/application.php', <<<'PHP'
<?php
use Illuminate\Container\Container;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Migrations\Migrator;

$kernel = Mockery::mock(Kernel::class);
$kernel->allows('bootstrap');
$kernel->allows('terminate');
$kernel->allows('handle')->andReturnUsing(static function ($input): int {
    echo $input->getFirstArgument(), PHP_EOL;

    return 0;
});
$migrator = Mockery::mock(Migrator::class);
$migrator->allows('repositoryExists')->andReturn(getenv('FIXTURE_MIGRATION_REPOSITORY_EXISTS') === '1');

$app = new Container();
$app->instance(Kernel::class, $kernel);
$app->instance('migrator', $migrator);

return $app;
PHP);
        $process = $this->runScript('scripts/dev/testing-artisan.php', [
            'FIXTURE_MIGRATION_REPOSITORY_EXISTS' => $repositoryExists,
        ], ['migrate:install']);

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $this->assertSame($expectedOutput, $process->getOutput());
    }

    public function test_bootstrap_refreshes_isolated_manifests_when_installed_packages_change(): void
    {
        $this->write('read-manifest.php', <<<'PHP'
<?php
require __DIR__.'/tests/bootstrap.php';

$manifest = new Illuminate\Foundation\PackageManifest(
    new Illuminate\Filesystem\Filesystem(),
    __DIR__,
    getenv('APP_PACKAGES_CACHE'),
);
echo json_encode($manifest->providers(), JSON_THROW_ON_ERROR);
PHP);
        $this->write('vendor/composer/installed.json', '{"packages":[{"name":"fixture/old","extra":{"laravel":{"providers":["OldProvider"]}}}]}');
        $environment = ['COMPOSER_VENDOR_DIR' => false];
        $process = $this->runScript('read-manifest.php', $environment);
        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $this->assertSame('["OldProvider"]', $process->getOutput());

        $cache = 'storage/cli-testing/bootstrap/cache';
        $this->write($cache.'/services.php', '<?php return [];');
        $this->write('bootstrap/cache/packages.php', '<?php return [];');
        $this->write('bootstrap/cache/services.php', '<?php return [];');
        $process = $this->runScript('read-manifest.php', $environment);
        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $this->assertFileExists($this->fixture.'/'.$cache.'/services.php');

        $this->write('vendor/composer/installed.json', '{"packages":[{"name":"fixture/new","extra":{"laravel":{"providers":["NewProvider"]}}}]}');
        $process = $this->runScript('read-manifest.php', $environment);
        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $this->assertSame('["NewProvider"]', $process->getOutput());
        $this->assertFileDoesNotExist($this->fixture.'/'.$cache.'/services.php');
        $this->assertFileExists($this->fixture.'/bootstrap/cache/packages.php');
        $this->assertFileExists($this->fixture.'/bootstrap/cache/services.php');

        $this->write('composer.json', '{"extra":{"laravel":{"dont-discover":["fixture/new"]}}}');
        $process = $this->runScript('read-manifest.php', $environment);
        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $this->assertSame('[]', $process->getOutput());
    }

    public function test_local_environment_is_allowed(): void
    {
        $process = $this->runScript('scripts/ci/assert-not-production.php', ['APP_ENV' => 'local']);

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
    }

    public function test_unknown_environment_is_refused(): void
    {
        unlink($this->fixture.'/.env');
        $process = $this->runScript('scripts/ci/assert-not-production.php', ['APP_ENV' => false]);

        $this->assertSame(1, $process->getExitCode());
        $this->assertStringContainsString('Cannot determine APP_ENV', $process->getErrorOutput());
    }

    public function test_custom_production_cache_is_checked(): void
    {
        $this->write('custom-cache.php', "<?php return ['app' => ['env' => 'production']];");

        foreach (['custom-cache.php', $this->fixture.'/custom-cache.php'] as $path) {
            $process = $this->runScript('scripts/ci/assert-not-production.php', [
                'APP_ENV' => 'local',
                'APP_CONFIG_CACHE' => $path,
            ]);

            $this->assertSame(1, $process->getExitCode(), $path);
            $this->assertStringContainsString('cannot run in production', $process->getErrorOutput());
        }

        $this->write('.env', "APP_ENV=local\n'APP_CONFIG_CACHE'=custom-cache.php\n");
        $process = $this->runScript('scripts/ci/assert-not-production.php');
        $this->assertSame(1, $process->getExitCode());
        $this->assertStringContainsString('cannot run in production', $process->getErrorOutput());
    }

    #[TestWith(['_SERVER'])]
    #[TestWith(['_ENV'])]
    public function test_production_cache_from_php_superglobals_is_checked(string $source): void
    {
        $this->write('custom-cache.php', "<?php return ['app' => ['env' => 'production']];");
        $this->write('local-cache.php', "<?php return ['app' => ['env' => 'local']];");
        $this->write('check-cache.php', <<<'PHP'
<?php
if ($argv[1] === '_SERVER') {
    $_SERVER['APP_CONFIG_CACHE'] = 'custom-cache.php';
} else {
    $_ENV['APP_CONFIG_CACHE'] = 'custom-cache.php';
}
require __DIR__.'/scripts/ci/assert-not-production.php';
PHP);

        foreach ([false, 'local-cache.php'] as $processCache) {
            $process = $this->runScript('check-cache.php', [
                'APP_ENV' => 'local',
                'APP_CONFIG_CACHE' => $processCache,
            ], [$source]);

            $this->assertSame(1, $process->getExitCode(), $process->getErrorOutput());
            $this->assertStringContainsString('cannot run in production', $process->getErrorOutput());
        }
    }

    public function test_preflight_refuses_existing_dotenv_without_composer(): void
    {
        unlink($this->fixture.'/vendor/autoload.php');
        $process = $this->runScript('scripts/ci/assert-not-production.php');

        $this->assertSame(1, $process->getExitCode());
        $this->assertStringContainsString('Composer dependencies are required to verify .env', $process->getErrorOutput());
    }

    public function test_preflight_refuses_malformed_dotenv(): void
    {
        $this->write('.env', "APP_ENV=\"production\n");
        $process = $this->runScript('scripts/ci/assert-not-production.php');

        $this->assertSame(1, $process->getExitCode());
        $this->assertStringContainsString('Cannot parse .env', $process->getErrorOutput());
    }

    public function test_preflight_refuses_interpolated_cache_paths(): void
    {
        $this->write('.env', 'APP_CONFIG_CACHE="${CACHE_DIR}/config.php"'."\n");
        $process = $this->runScript('scripts/ci/assert-not-production.php');

        $this->assertSame(1, $process->getExitCode());
        $this->assertStringContainsString('Cannot resolve the application environment', $process->getErrorOutput());
    }

    public function test_unverifiable_cached_environment_is_refused(): void
    {
        $this->write('bootstrap/cache/config.php', '<?php return [];');
        $process = $this->runScript('scripts/ci/assert-not-production.php');

        $this->assertSame(1, $process->getExitCode());
        $this->assertStringContainsString('Cannot determine APP_ENV from cached configuration', $process->getErrorOutput());
    }

    public function test_preflight_allows_fresh_ci_without_php_or_composer(): void
    {
        unlink($this->fixture.'/.env');
        unlink($this->fixture.'/vendor/autoload.php');
        $process = $this->runScript('scripts/ci/assert-not-production.php');
        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());

        mkdir($this->fixture.'/without-php');
        symlink('/usr/bin/dirname', $this->fixture.'/without-php/dirname');
        $process = $this->runScript('scripts/ci/assert-not-production.sh', ['PATH' => $this->fixture.'/without-php']);

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
    }
}
