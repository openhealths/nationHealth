<?php

declare(strict_types=1);

namespace Tests\Unit\Database;

use Closure;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MigrationPortabilityTest extends TestCase
{
    #[DataProvider('episodeMigrations')]
    public function test_foreign_keys_fit_database_limits_and_preserve_history_cascade(string $driver, string $path): void
    {
        $sql = $this->compileMigration($driver, $path);
        $this->assertConstraintLengths($driver, $sql);
        $this->assertStringContainsString(
            'foreign key (episode_diagnoses_history_id) references episode_diagnoses_history (id) on delete cascade',
            str_replace(['`', '"'], '', $sql)
        );
    }

    #[DataProvider('reorganizationMigrations')]
    public function test_reorganization_uses_portable_constraints_and_column_positions(string $driver, string $path): void
    {
        $sql = $this->compileMigration($driver, $path, columnsExist: false);

        $this->assertConstraintLengths($driver, $sql);
        $this->assertDoesNotMatchRegularExpression('/create table[^;\n]+\bfirst\b/i', $sql);
        $this->assertStringContainsString(
            'foreign key (declaration_request_id) references declaration_requests (id) on delete cascade',
            str_replace(['`', '"'], '', $sql)
        );
    }

    #[DataProvider('reorganizationRollbackNames')]
    public function test_reorganization_rollback_supports_existing_and_new_constraint_names(string $driver, string $name): void
    {
        $sql = $this->compileMigration(
            $driver,
            'migrations/update/0_1/2026_05_19_112112_add_declaration_request_to_reorganization_employee_declarations_table.php',
            method: 'down',
            foreignKeys: [['columns' => ['declaration_request_id'], 'name' => $name]]
        );

        $this->assertStringContainsString(
            ($driver === 'pgsql' ? 'drop constraint ' : 'drop foreign key ').$name,
            str_replace(['`', '"'], '', $sql)
        );
    }

    #[DataProvider('carePlanMigrations')]
    public function test_care_plan_pivot_indexes_fit_database_limits_and_remain_unique(string $driver, string $path): void
    {
        $sql = $this->compileMigration($driver, $path, createOnly: true);

        $this->assertConstraintLengths($driver, $sql);
        $this->assertSame(5, preg_match_all('/\bunique\b/i', $sql));
        $this->assertStringContainsString('(activity_id, concept_id)', str_replace(['`', '"'], '', $sql));
    }

    #[DataProvider('statusMigrations')]
    public function test_status_migrations_use_each_drivers_constraints(string $driver, string $path, string $method, bool $nullable): void
    {
        $sql = strtolower(str_replace(['`', '"'], '', $this->compileMigration($driver, $path, method: $method)));

        $this->assertStringNotContainsString('drop foreign key', $sql);
        if ($driver === 'pgsql') {
            $this->assertStringContainsString('check (status in (', $sql);
        } else {
            $this->assertStringNotContainsString('_status_check', $sql);
            $this->assertMatchesRegularExpression('/modify status enum\([^\n]+\) '.($nullable ? 'null' : 'not null').'/', $sql);
        }
    }

    #[DataProvider('paperReferralDateMigrations')]
    public function test_paper_referral_date_uses_driver_specific_conversion_and_stays_nullable(string $driver, string $method): void
    {
        $sql = strtolower(preg_replace('/\s+/', ' ', str_replace(['`', '"'], '', $this->compileMigration(
            $driver,
            'migrations/update/0_1/2026_05_25_000000_change_service_request_date_column_in_paper_referrals_table.php',
            method: $method
        ))));

        if ($driver === 'pgsql') {
            $this->assertStringContainsString('using service_request_date::', $sql);
            if ($method === 'up') {
                $this->assertStringContainsString('drop not null', $sql);
            }
        } else {
            $this->assertStringNotContainsString('using service_request_date::', $sql);
            $this->assertStringContainsString(
                'modify service_request_date '.($method === 'up' ? 'timestamp' : 'varchar(255)').' null',
                $sql
            );
        }
    }

    protected function compileMigration(string $driver, string $path, bool $columnsExist = true, string $method = 'up', array $foreignKeys = [], bool $createOnly = false): string
    {
        $connection = DB::build(['driver' => $driver, 'database' => 'testing', 'prefix' => '']);
        $pdo = $this->createStub(PDO::class);
        $pdo->method('getAttribute')->willReturn(match ($driver) {
            'mysql' => '8.0.46',
            'mariadb' => '10.6.23-MariaDB',
            'pgsql' => '14.24',
        });
        $connection->setPdo($pdo);
        $connection->useDefaultSchemaGrammar();
        $queries = [];
        Schema::shouldReceive('hasColumn')->andReturn($columnsExist);
        Schema::shouldReceive('hasTable')->andReturn(false);
        Schema::shouldReceive('getForeignKeys')->andReturn($foreignKeys);
        foreach (['create', 'table'] as $operation) {
            if ($createOnly && $operation === 'table') {
                Schema::shouldReceive($operation)->andReturnNull();

                continue;
            }
            Schema::shouldReceive($operation)->andReturnUsing(static function (string $table, Closure $callback) use ($connection, &$queries, $operation): void {
                $blueprint = new Blueprint($connection, $table);
                if ($operation === 'create') {
                    $blueprint->create();
                }
                $callback($blueprint);
                array_push($queries, ...$blueprint->toSql());
            });
        }

        $original = DB::getDefaultConnection();
        DB::setDefaultConnection($connection->getName());
        try {
            $executed = $connection->pretend(static fn () => (require database_path($path))->{$method}());
            array_push($queries, ...array_column($executed, 'query'));
        } finally {
            DB::setDefaultConnection($original);
        }

        return implode("\n", $queries);
    }

    protected function assertConstraintLengths(string $driver, string $sql): void
    {
        preg_match_all('/\b(?:constraint|index|unique)\s+[`"]([^`"]+)[`"]/i', $sql, $constraints);
        $this->assertNotEmpty($constraints[1]);
        foreach ($constraints[1] as $constraint) {
            $this->assertLessThanOrEqual($driver === 'pgsql' ? 63 : 64, strlen($constraint), $constraint);
        }
    }

    public static function episodeMigrations(): iterable
    {
        foreach (['pgsql', 'mysql', 'mariadb'] as $driver) {
            yield $driver.' install' => [$driver, 'migrations/install/2025_12_10_000060_create_episodes_table.php'];
            yield $driver.' update' => [$driver, 'migrations/update/0_1/2026_04_22_000000_update_episodes_table_add_missing_columns.php'];
        }
    }

    public static function reorganizationMigrations(): iterable
    {
        foreach (['pgsql', 'mysql', 'mariadb'] as $driver) {
            yield $driver.' install' => [$driver, 'migrations/install/2026_05_11_153744_create_reorganization_employee_declarations_table.php'];
            yield $driver.' update' => [$driver, 'migrations/update/0_1/2026_05_19_112112_add_declaration_request_to_reorganization_employee_declarations_table.php'];
        }
    }

    public static function reorganizationRollbackNames(): iterable
    {
        foreach (['pgsql', 'mysql', 'mariadb'] as $driver) {
            yield $driver.' new name' => [$driver, 'reorg_employee_declarations_request_fk'];
        }
        yield 'PostgreSQL legacy truncated name' => ['pgsql', substr('reorganization_employee_declarations_declaration_request_id_foreign', 0, 63)];
    }

    public static function carePlanMigrations(): iterable
    {
        foreach (['pgsql', 'mysql', 'mariadb'] as $driver) {
            yield $driver.' install' => [$driver, 'migrations/install/2026_10_19_081226_complete_care_plan_fhir_storage.php'];
            yield $driver.' update' => [$driver, 'migrations/update/0_1/2026_10_19_081226_complete_care_plan_fhir_storage.php'];
        }
    }

    public static function statusMigrations(): iterable
    {
        $migrations = [
            '2026_06_02_000000_add_draft_status_to_episodes_table.php',
            '2026_06_08_114019_add_draft_status_to_diagnostic_reports_table.php',
            '2026_06_29_000000_add_draft_status_to_prepersons_table.php',
            '2026_07_14_000000_update_declarations_status_constraint.php',
            '2026_07_22_120000_add_cancelled_status_to_merge_requests_table.php',
            '2026_07_24_120000_add_expired_status_to_merge_requests_table.php',
            '2026_07_27_162231_add_draft_status_to_encounters_table.php',
            '2026_09_28_120000_add_draft_status_to_specimens_table.php',
            '2026_10_13_000000_add_draft_status_to_procedures_table.php',
        ];
        foreach (['pgsql', 'mysql', 'mariadb'] as $driver) {
            foreach ($migrations as $file) {
                foreach (['up', 'down'] as $method) {
                    yield $driver.' '.$method.' '.$file => [$driver, 'migrations/update/0_1/'.$file, $method, str_contains($file, 'prepersons')];
                }
            }
            yield $driver.' device dispenses' => [$driver, 'migrations/update/0_1/2026_10_02_130510_update_device_dispenses_for_sync.php', 'up', false];
        }
    }

    public static function paperReferralDateMigrations(): iterable
    {
        foreach (['pgsql', 'mysql', 'mariadb'] as $driver) {
            foreach (['up', 'down'] as $method) {
                yield $driver.' '.$method => [$driver, $method];
            }
        }
    }
}
