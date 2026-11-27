<?php

declare(strict_types=1);

namespace Tests\Unit\Database;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;
use Tests\TestCase;

class RequestReferenceMigrationPortabilityTest extends TestCase
{
    #[TestWith(['mysql'])]
    #[TestWith(['mariadb'])]
    public function test_complete_fhir_schema_needs_no_transactional_ddl(string $driver): void
    {
        $connection = $this->readOnlyConnection($driver);

        $connection->pretend(static fn () => (require database_path('migrations/update/0_1/2026_09_11_150000_migrate_request_refs_to_fhir_identifiers.php'))->up());

        $this->assertSame(0, $connection->transactionLevel());
    }

    #[DataProvider('incompleteSchemas')]
    public function test_legacy_or_incomplete_fhir_schemas_stop_before_any_mutation(string $driver, string $state, string $message): void
    {
        $connection = $this->readOnlyConnection($driver, $state);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage($message);

        $connection->pretend(static fn () => (require database_path('migrations/update/0_1/2026_09_11_150000_migrate_request_refs_to_fhir_identifiers.php'))->up());
    }

    protected function readOnlyConnection(string $driver, string $state = 'complete'): Connection
    {
        $connection = DB::build(['driver' => $driver, 'database' => 'testing']);
        $pdo = $this->createStub(PDO::class);
        $pdo->method('getAttribute')->willReturn($driver === 'mysql' ? '8.0.46' : '10.6.23-MariaDB');
        $connection->setPdo($pdo);
        $connection->useDefaultSchemaGrammar();
        DB::setDefaultConnection($connection->getName());
        $connection->beforeExecuting(static function (string $sql): void {
            if (!str_starts_with(strtolower($sql), 'select')) {
                throw new LogicException('No schema or data mutations are allowed during preflight.');
            }
        });
        Schema::shouldReceive('table')->never();
        Schema::shouldReceive('create')->never();
        Schema::shouldReceive('hasTable')->andReturn(true);
        Schema::shouldReceive('hasColumn')->andReturnUsing(static function (string $table, string $column) use ($state): bool {
            return match ($column) {
                'intent' => in_array($state, ['legacy', 'ambiguous'], true),
                'intent_id' => $state !== 'legacy',
                'category_id' => $state !== 'missing category',
                'source' => $state !== 'missing source',
                default => true,
            };
        });
        $legacy = $state === 'legacy';
        Schema::shouldReceive('getForeignKeys')->andReturn([
            ['columns' => ['based_on_id'], 'foreign_table' => $legacy || $state === 'wrong reference' ? 'care_plan_activities' : 'identifiers'],
            ['columns' => ['context_id'], 'foreign_table' => $legacy ? 'encounters' : 'identifiers'],
            ['columns' => ['intent_id'], 'foreign_table' => $state === 'wrong intent' ? 'identifiers' : 'codings'],
            ['columns' => ['category_id'], 'foreign_table' => 'codeable_concepts'],
            ['columns' => ['priority_id'], 'foreign_table' => 'codeable_concepts'],
        ]);
        Schema::shouldReceive('getColumns')->andReturn([['name' => 'employee_id', 'nullable' => $state !== 'required employee']]);

        return $connection;
    }

    public static function incompleteSchemas(): iterable
    {
        foreach (['mysql', 'mariadb'] as $driver) {
            foreach ([
                'legacy' => 'Legacy FHIR reference conversion requires transactional DDL',
                'ambiguous' => 'Ambiguous migration state',
                'wrong reference' => 'Unexpected reference constraint',
                'wrong intent' => 'Incomplete FHIR storage',
                'missing category' => 'Incomplete FHIR storage',
                'missing source' => 'Medication FHIR storage requires transactional DDL',
                'required employee' => 'Medication FHIR storage requires transactional DDL',
            ] as $state => $message) {
                yield $driver.' '.$state => [$driver, $state, $message];
            }
        }
    }
}
