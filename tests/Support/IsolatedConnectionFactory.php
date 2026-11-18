<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Database\Connectors\ConnectionFactory;
use Illuminate\Support\ConfigurationUrlParser;
use PDO;
use Pdo\Mysql;
use RuntimeException;

class IsolatedConnectionFactory extends ConnectionFactory
{
    public function make(array $config, $name = null)
    {
        $config = (new ConfigurationUrlParser())->parseConfiguration($config);
        if (!isset($config['read'])) {
            $this->assertIsolated($config, $name);
        }

        // Check every replica before Laravel randomly selects a read/write configuration.
        foreach (['read', 'write', 'direct'] as $type) {
            if (!isset($config[$type])) {
                continue;
            }
            $targets = isset($config[$type][0]) ? $config[$type] : [$config[$type]];
            foreach ($targets as $target) {
                $target = array_merge($config, $target);
                $this->assertIsolated($target, $name);
                $target = (new ConfigurationUrlParser())->parseConfiguration($target);
                $this->assertIsolated($target, $name);
            }
        }

        return parent::make($config, $name);
    }

    // Check the configuration actually selected by Laravel, including nested direct endpoints.
    protected function createPdoResolver(array $config)
    {
        $this->assertIsolated($config, $config['name'] ?? null);

        return parent::createPdoResolver($config);
    }

    protected function assertIsolated(array $config, ?string $name): void
    {
        $driver = $config['driver'] ?? null;
        $database = $config['database'] ?? null;
        if ($driver === 'sqlsrv' && ($config['odbc'] ?? false) === true) {
            throw new RuntimeException(
                'Refusing unsafe test database connection ['.($name ?? 'unnamed').']. '
                .'The SQL Server ODBC data source determines the target database.'
            );
        }
        if (in_array($driver, ['mysql', 'mariadb'], true) && extension_loaded('pdo_mysql')) {
            $prefix = class_exists(Mysql::class, false) ? Mysql::class.'::ATTR_' : PDO::class.'::MYSQL_ATTR_';
            $options = $config['options'] ?? [];
            foreach (['INIT_COMMAND', 'READ_DEFAULT_FILE', 'READ_DEFAULT_GROUP'] as $option) {
                $attribute = $prefix.$option;
                // mysqlnd does not expose the option-file attributes.
                if (defined($attribute) && array_key_exists(constant($attribute), $options)) {
                    throw new RuntimeException(
                        'Refusing unsafe test database connection ['.($name ?? 'unnamed').']. '
                        .'MySQL PDO option ['.$option.'] is not allowed.'
                    );
                }
            }
        }

        if ($driver === 'sqlite' && $database === ':memory:') {
            return;
        }
        if (in_array($driver, ['pgsql', 'mysql', 'mariadb', 'sqlsrv'], true) && $database === 'testing'
            && ($config['connect_via_database'] ?? 'testing') === 'testing') {
            return;
        }

        throw new RuntimeException(
            'Refusing unsafe test database connection ['.($name ?? 'unnamed').']. '
            .'Only SQL database [testing] or SQLite [:memory:] is allowed.'
        );
    }
}
