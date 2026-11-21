<?php

declare(strict_types=1);

namespace Tests\Unit\Config;

use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\Attributes\DataProvider;
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
        $this->write('.env', "APP_ENV=local\n");
        $this->write('.env.testing', "APP_ENV=testing\nDB_CONNECTION=pgsql\nDB_DATABASE=testing\nDB_USERNAME=fixture\nDB_PASSWORD=fixture\nAPP_KEY=fixture\n");
        $this->write('vendor/autoload.php', '<?php');

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

    protected function runScript(string $script, array $environment = []): Process
    {
        $process = new Process([
            str_ends_with($script, '.php') ? PHP_BINARY : '/bin/bash',
            $this->fixture.'/'.$script,
        ], $this->fixture, array_replace([
            'APP_ENV' => 'testing',
            'APP_CONFIG_CACHE' => false,
            'DB_CONNECTION' => 'pgsql',
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
            $this->write('.env', "export APP_ENV=\"production\" # deployment\n");
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

    #[DataProvider('databaseStates')]
    public function test_setup_connects_to_testing_before_creating_it(string $state, array $expectedCalls): void
    {
        $autoload = var_export(dirname(__DIR__, 3).'/vendor/autoload.php', true);
        $this->write('vendor/autoload.php', "<?php require $autoload;");
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
        (new Filesystem())->deleteDirectory($this->fixture.'/vendor');
        foreach (['vendor', 'config', 'routes', 'app', 'resources'] as $directory) {
            symlink($project.'/'.$directory, $this->fixture.'/'.$directory);
        }
        foreach (['bootstrap/app.php', 'tests/application.php', 'composer.json'] as $file) {
            $this->write($file, file_get_contents($project.'/'.$file));
        }
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
        $process = $this->runScript('scripts/ci/assert-not-production.php', ['APP_CONFIG_CACHE' => 'custom-cache.php']);

        $this->assertSame(1, $process->getExitCode());
        $this->assertStringContainsString('cannot run in production', $process->getErrorOutput());
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
        mkdir($this->fixture.'/without-php');
        symlink('/usr/bin/dirname', $this->fixture.'/without-php/dirname');
        $process = $this->runScript('scripts/ci/assert-not-production.sh', ['PATH' => $this->fixture.'/without-php']);

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
    }
}
