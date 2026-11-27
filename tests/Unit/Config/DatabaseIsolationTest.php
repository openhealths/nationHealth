<?php

declare(strict_types=1);

namespace Tests\Unit\Config;

use App\Models\User;
use Closure;
use Illuminate\Support\Facades\DB;
use PDO;
use Pdo\Mysql;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;
use Tests\Support\IsolatedConnectionFactory;
use Tests\TestCase;

class DatabaseIsolationTest extends TestCase
{
    public function test_guard_is_installed_in_the_application_connection_factory(): void
    {
        $this->assertInstanceOf(IsolatedConnectionFactory::class, $this->app['db.factory']);
    }

    public function test_runtime_uses_local_resources_and_separate_laravel_files(): void
    {
        $this->assertSame('array', config('cache.default'));
        $this->assertSame('array', config('session.driver'));
        $this->assertSame('array', config('mail.default'));
        $this->assertSame('sync', config('queue.default'));
        $this->assertSame('local', config('filesystems.default'));
        $storage = base_path('storage/cli-testing');
        $this->assertSame($storage, storage_path());
        $this->assertSame($storage.'/framework/views', config('view.compiled'));
        foreach ([$this->app->getCachedConfigPath(), $this->app->getCachedServicesPath(),
            $this->app->getCachedPackagesPath(), $this->app->getCachedRoutesPath(), $this->app->getCachedEventsPath()] as $file) {
            $this->assertSame($storage.'/bootstrap/cache', dirname($file));
        }
    }

    #[DataProvider('unsafeConnections')]
    public function test_rejects_unsafe_named_connections_before_opening_pdo(array $configuration): void
    {
        config(['database.connections.secondary' => $configuration]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Refusing unsafe test database connection [secondary]');

        DB::connection('secondary');
    }

    public static function unsafeConnections(): array
    {
        $safe = ['driver' => 'pgsql', 'database' => 'testing', 'host' => '127.0.0.1', 'port' => 1];
        $split = ['driver' => 'pgsql', 'read' => ['database' => 'testing'], 'write' => ['database' => 'testing']];
        $sqlServer = ['driver' => 'sqlsrv', 'database' => 'testing'];
        $odbc = ['odbc' => true, 'odbc_datasource_name' => 'external_fixture'];

        return [
            'different database' => [array_replace($safe, ['database' => 'application_live'])],
            'URL overrides database' => [array_replace($safe, ['url' => 'postgresql://127.0.0.1:1/application_live'])],
            'URL query overrides database' => [array_replace($safe, ['url' => 'postgresql://127.0.0.1:1/testing?database=application_live'])],
            'physical PostgreSQL database' => [$safe + ['connect_via_database' => 'application_live']],
            'read physical database' => [$safe + ['read' => ['connect_via_database' => 'application_live'], 'write' => []]],
            'direct database' => [$safe + ['direct' => ['database' => 'application_live']]],
            'one unsafe direct replica' => [$safe + ['direct' => [[], ['database' => 'application_live']]]],
            'read database' => [$safe + ['read' => ['database' => 'application_live'], 'write' => []]],
            'write database' => [$safe + ['read' => [], 'write' => ['database' => 'application_live']]],
            'one unsafe read replica' => [$safe + ['read' => [[], ['database' => 'application_live']], 'write' => []]],
            'one unsafe write replica' => [$safe + ['read' => [], 'write' => [[], ['database' => 'application_live']]]],
            'no inherited database for read' => [array_replace($split, ['read' => []])],
            'one unsafe write replica without a shared database' => [array_replace($split, [
                'write' => [$split['write'], ['database' => 'application_live']],
            ])],
            'read URL' => [$safe + ['read' => ['url' => 'postgresql://127.0.0.1:1/application_live'], 'write' => []]],
            'SQLite file' => [['driver' => 'sqlite', 'database' => '/tmp/application.sqlite']],
            'MySQL database' => [['driver' => 'mysql', 'database' => 'application_live']],
            'SQL Server ODBC' => [$sqlServer + $odbc],
            'SQL Server ODBC read replica' => [$sqlServer + ['read' => [[], $odbc], 'write' => []]],
            'unconfigured storage driver' => [['driver' => 'mongodb', 'database' => 'application_live']],
        ];
    }

    #[RequiresPhpExtension('pdo_mysql')]
    #[DataProvider('unsafeMysqlOptions')]
    public function test_rejects_unsafe_mysql_options_before_opening_pdo(
        string $driver,
        ?string $replica,
        string $option,
        string $value
    ): void {
        $prefix = class_exists(Mysql::class, false) ? Mysql::class.'::ATTR_' : PDO::class.'::MYSQL_ATTR_';
        $attribute = $prefix.$option;
        if (!defined($attribute)) {
            $this->markTestSkipped('The installed PDO MySQL driver does not expose '.$attribute.'.');
        }

        $configuration = ['driver' => $driver, 'database' => 'testing'];
        $options = [constant($attribute) => $value];

        if ($replica === null) {
            $configuration['options'] = $options;
        } else {
            $configuration['read'] = [];
            $configuration['write'] = [];
            $configuration[$replica] = [[], ['options' => $options]];
        }

        config(['database.connections.secondary' => $configuration]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Refusing unsafe test database connection [secondary]. MySQL PDO option ['.$option.'] is not allowed.');

        DB::connection('secondary');
    }

    public static function unsafeMysqlOptions(): iterable
    {
        $options = [
            'INIT_COMMAND' => 'USE application_live',
            'READ_DEFAULT_FILE' => 'fixture.cnf',
            'READ_DEFAULT_GROUP' => 'fixture',
        ];
        foreach (['mysql', 'mariadb'] as $driver) {
            foreach ([null, 'read', 'write'] as $replica) {
                foreach ($options as $option => $value) {
                    yield $driver.' '.($replica ?? 'primary').' '.$option => [$driver, $replica, $option, $value];
                }
            }
        }
    }

    public function test_model_cannot_use_an_unsafe_secondary_connection(): void
    {
        config(['database.connections.secondary' => ['driver' => 'pgsql', 'database' => 'application_live']]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Refusing unsafe test database connection [secondary]');

        User::on('secondary')->get();
    }

    public function test_dynamic_connection_cannot_bypass_the_guard(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Refusing unsafe test database connection');

        DB::build(['driver' => 'pgsql', 'database' => 'application_live']);
    }

    public function test_reconnect_rechecks_changed_connection_configuration(): void
    {
        config(['database.connections.secondary' => ['driver' => 'pgsql', 'database' => 'testing']]);
        $this->assertSame('testing', DB::connection('secondary')->getDatabaseName());
        config(['database.connections.secondary.database' => 'application_live']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Refusing unsafe test database connection [secondary]');

        DB::reconnect('secondary');
    }

    #[TestWith(['pgsql'])]
    #[TestWith(['mysql'])]
    #[TestWith(['mariadb'])]
    public function test_secondary_connection_keeps_the_testing_target_without_opening_pdo(string $driver): void
    {
        config(['database.connections.secondary' => [
            'driver' => $driver,
            'database' => 'testing',
            'options' => [PDO::ATTR_EMULATE_PREPARES => false],
        ]]);

        $this->assertSame('testing', DB::connection('secondary')->getDatabaseName());
    }

    #[TestWith([[]])]
    #[TestWith([['database' => 'application_live']])]
    public function test_read_write_connection_uses_each_effective_testing_database(array $shared): void
    {
        config(['database.connections.secondary' => $shared + [
            'driver' => 'pgsql',
            'read' => ['database' => 'testing'],
            'write' => ['database' => 'testing'],
        ]]);

        $connection = DB::connection('secondary');

        $this->assertSame('testing', $connection->getDatabaseName());
        $this->assertInstanceOf(Closure::class, $connection->getRawReadPdo());
        $this->assertInstanceOf(Closure::class, $connection->getRawPdo());
    }

    public function test_sql_server_allows_the_testing_database_with_odbc_disabled(): void
    {
        config(['database.connections.secondary' => [
            'driver' => 'sqlsrv',
            'database' => 'testing',
            'odbc' => false,
            'odbc_datasource_name' => 'external_fixture',
        ]]);

        $this->assertSame('testing', DB::connection('secondary')->getDatabaseName());
    }

    public function test_named_in_memory_connections_keep_their_data_separate(): void
    {
        $configuration = ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''];
        config([
            'database.connections.first_fixture' => $configuration,
            'database.connections.second_fixture' => $configuration,
        ]);
        $first = DB::connection('first_fixture');
        $second = DB::connection('second_fixture');
        foreach ([$first, $second] as $connection) {
            $connection->statement('CREATE TABLE isolation_probe (id INTEGER PRIMARY KEY)');
        }
        $first->table('isolation_probe')->insert(['id' => 1]);

        $this->assertSame(1, $first->table('isolation_probe')->count());
        $this->assertSame(0, $second->table('isolation_probe')->count());
    }
}
