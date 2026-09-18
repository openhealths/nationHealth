<?php

declare(strict_types=1);

namespace Tests\Feature\MedicalEvents;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class RequestReferenceMigrationTest extends TestCase
{
    private string $originalConnection;

    private string $schema;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('This migration requires PostgreSQL transactional DDL.');
        }
        $this->assertSame('testing', DB::getDatabaseName());
        $this->originalConnection = DB::getDefaultConnection();
        $this->schema = 'pr792_'.strtolower(Str::random(12));
        DB::statement('CREATE SCHEMA '.$this->schema);
        config(['database.connections.pr792_migration' => array_merge(
            config('database.connections.'.$this->originalConnection),
            ['search_path' => $this->schema]
        )]);
        DB::setDefaultConnection('pr792_migration');
        Schema::clearResolvedInstance('db.schema');

        foreach (['2025_12_10_000052_create_codings_table', '2025_12_10_000054_create_codeable_concepts_table', '2025_12_10_000056_create_identifiers_table'] as $file) {
            (require database_path('migrations/install/'.$file.'.php'))->up();
        }
        foreach (['care_plan_activities', 'encounters'] as $table) {
            Schema::create($table, static function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->nullable();
            });
        }
        foreach (['medication_request_requests', 'service_request_requests', 'device_request_requests'] as $table) {
            Schema::create($table, static function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('employee_id')->nullable();
                $table->string('source')->default('local');
                $table->string('intent')->nullable();
                $table->string('category')->nullable();
                $table->string('priority')->nullable();
                $table->foreignId('based_on_id')->nullable()->constrained('care_plan_activities');
                $table->foreignId('context_id')->nullable()->constrained('encounters');
            });
        }
    }

    protected function tearDown(): void
    {
        if (isset($this->originalConnection)) {
            DB::setDefaultConnection($this->originalConnection);
            Schema::clearResolvedInstance('db.schema');
            DB::purge('pr792_migration');
            DB::statement('DROP SCHEMA '.$this->schema.' CASCADE');
        }
        parent::tearDown();
    }

    public function test_legacy_references_survive_conversion_and_a_second_run(): void
    {
        $activityUuid = (string) Str::uuid();
        $encounterUuid = (string) Str::uuid();
        DB::table('care_plan_activities')->insert(['id' => 70, 'uuid' => $activityUuid]);
        DB::table('encounters')->insert(['id' => 80, 'uuid' => $encounterUuid]);
        foreach (['medication_request_requests', 'service_request_requests', 'device_request_requests'] as $table) {
            DB::table($table)->insert(['intent' => 'order', 'category' => 'community', 'priority' => 'routine', 'based_on_id' => 70, 'context_id' => 80]);
        }

        $migration = require database_path('migrations/update/0_1/2026_09_11_150000_migrate_request_refs_to_fhir_identifiers.php');
        $migration->up();
        $identifierCount = DB::table('identifiers')->count();
        $migration->up();
        $this->assertSame($identifierCount, DB::table('identifiers')->count());

        foreach (['medication_request_requests', 'service_request_requests', 'device_request_requests'] as $table) {
            $row = DB::table($table)->first();
            $this->assertSame($activityUuid, DB::table('identifiers')->where('id', $row->based_on_id)->value('value'));
            $this->assertSame($encounterUuid, DB::table('identifiers')->where('id', $row->context_id)->value('value'));
            $this->assertSame('order', DB::table('codings')->where('id', $row->intent_id)->value('code'));
            $this->assertFalse(Schema::hasColumn($table, 'intent'));
        }
    }

    public function test_missing_uuid_stops_conversion_before_changing_any_table(): void
    {
        DB::table('encounters')->insert(['id' => 80, 'uuid' => null]);
        DB::table('device_request_requests')->insert(['context_id' => 80]);
        $migration = require database_path('migrations/update/0_1/2026_09_11_150000_migrate_request_refs_to_fhir_identifiers.php');
        try {
            $migration->up();
            $this->fail('An unresolved UUID must not be discarded.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Unresolvable UUID', $e->getMessage());
            $this->assertTrue(Schema::hasColumn('medication_request_requests', 'intent'));
            $this->assertFalse(Schema::hasColumn('medication_request_requests', 'intent_id'));
            $this->assertSame(80, DB::table('device_request_requests')->value('context_id'));
        }
    }

    public function test_partial_shape_is_not_silently_marked_as_complete(): void
    {
        Schema::table('medication_request_requests', static fn (Blueprint $table) => $table->unsignedBigInteger('intent_id')->nullable());
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Ambiguous migration state');
        (require database_path('migrations/update/0_1/2026_09_11_150000_migrate_request_refs_to_fhir_identifiers.php'))->up();
    }

    public function test_failure_in_the_last_table_rolls_back_earlier_schema_changes(): void
    {
        DB::listen(static function (QueryExecuted $query): void {
            if (str_starts_with($query->sql, 'alter table "device_request_requests"')) {
                throw new RuntimeException('Simulated interruption');
            }
        });

        try {
            (require database_path('migrations/update/0_1/2026_09_11_150000_migrate_request_refs_to_fhir_identifiers.php'))->up();
            $this->fail('The simulated interruption must abort the migration.');
        } catch (RuntimeException $e) {
            $this->assertSame('Simulated interruption', $e->getMessage());
            $this->assertTrue(Schema::hasColumn('medication_request_requests', 'intent'));
            $this->assertFalse(Schema::hasColumn('medication_request_requests', 'intent_id'));
            $this->assertTrue(Schema::hasColumn('service_request_requests', 'intent'));
        }
    }

    public function test_resource_type_upgrade_preserves_existing_cache_and_is_idempotent(): void
    {
        Schema::table('medication_request_requests', static function (Blueprint $table) {
            $table->string('medication_id');
            $table->decimal('medication_qty', 15, 2);
        });
        DB::table('medication_request_requests')->insert([
            ['id' => 1, 'source' => 'local', 'medication_id' => 'INN-1', 'medication_qty' => 7],
            ['id' => 2, 'source' => 'ehealth', 'medication_id' => 'INN-2', 'medication_qty' => 3],
        ]);
        $migration = require database_path('migrations/update/0_1/2026_09_17_120000_add_medication_resource_type.php');
        $migration->up();
        $this->assertSame('medication_request_request', DB::table('medication_request_requests')->where('id', 1)->value('resource_type'));
        $this->assertSame('medication_request', DB::table('medication_request_requests')->where('id', 2)->value('resource_type'));

        DB::table('medication_request_requests')->where('id', 2)->update(['resource_type' => 'medication_request_request']);
        $migration->up();
        $this->assertSame('medication_request_request', DB::table('medication_request_requests')->where('id', 2)->value('resource_type'));
        $this->assertSame('7.00', DB::table('medication_request_requests')->where('id', 1)->value('medication_qty'));
    }

    public static function migrationHistoryStates(): array
    {
        return ['FHIR pending' => [false], 'FHIR already ran' => [true]];
    }

    #[DataProvider('migrationHistoryStates')]
    public function test_dump_restore_and_migrator_preserve_references_and_history(bool $alreadyRan): void
    {
        $finder = new ExecutableFinder();
        if (!$finder->find('pg_dump') || !$finder->find('psql')) {
            $this->markTestSkipped('Dump/restore verification requires PostgreSQL client tools.');
        }

        Schema::table('medication_request_requests', static function (Blueprint $table) {
            $table->string('medication_id');
            $table->decimal('medication_qty', 15, 2);
        });
        $activityUuid = (string) Str::uuid();
        $encounterUuid = (string) Str::uuid();
        DB::table('care_plan_activities')->insert(['id' => 70, 'uuid' => $activityUuid]);
        DB::table('encounters')->insert(['id' => 80, 'uuid' => $encounterUuid]);
        foreach (['medication_request_requests', 'service_request_requests', 'device_request_requests'] as $table) {
            $row = ['id' => 1, 'intent' => 'order', 'based_on_id' => 70, 'context_id' => 80];
            if ($table === 'medication_request_requests') {
                $row += ['source' => 'ehealth', 'medication_id' => 'INN-1', 'medication_qty' => 7];
            }
            DB::table($table)->insert($row);
        }
        $fhir = '2026_09_11_150000_migrate_request_refs_to_fhir_identifiers';
        $resourceType = '2026_09_17_120000_add_medication_resource_type';
        Artisan::call('migrate:install', ['--database' => 'pr792_migration']);
        if ($alreadyRan) {
            (require database_path('migrations/update/0_1/'.$fhir.'.php'))->up();
            DB::table('migrations')->insert(['migration' => $fhir, 'batch' => 1]);
        }

        $config = config('database.connections.pr792_migration');
        $connection = ['--host='.$config['host'], '--port='.$config['port'], '--username='.$config['username'], '--dbname=testing'];
        $env = ['PGPASSWORD' => (string) $config['password']];
        $dump = tempnam(sys_get_temp_dir(), 'pr792-dump-');
        try {
            (new Process(array_merge(['pg_dump'], $connection, [
                '--schema='.$this->schema, '--no-owner', '--no-privileges', '--file='.$dump,
            ]), null, $env))->mustRun();
            $this->assertGreaterThan(0, filesize($dump));
            // Drop only this test's random schema, then restore its real dump into testing.
            DB::connection($this->originalConnection)->statement('DROP SCHEMA '.$this->schema.' CASCADE');
            (new Process(array_merge(['psql'], $connection, [
                '--single-transaction', '--set=ON_ERROR_STOP=1', '--file='.$dump,
            ]), null, $env))->mustRun();
        } finally {
            unlink($dump);
        }

        $options = [
            '--database' => 'pr792_migration', '--force' => true,
            '--path' => array_map(static fn (string $name): string => database_path('migrations/update/0_1/'.$name.'.php'), [$fhir, $resourceType]),
            '--realpath' => true,
        ];
        $this->assertSame(0, Artisan::call('migrate', $options));
        $identifierCount = DB::table('identifiers')->count();
        $this->assertSame(0, Artisan::call('migrate', $options));
        $this->assertSame($identifierCount, DB::table('identifiers')->count());
        $this->assertSame(2, DB::table('migrations')->count());
        $this->assertSame(1, DB::table('migrations')->where('migration', $fhir)->value('batch'));
        $this->assertSame($alreadyRan ? 2 : 1, DB::table('migrations')->where('migration', $resourceType)->value('batch'));
        foreach (['medication_request_requests', 'service_request_requests', 'device_request_requests'] as $table) {
            $this->assertSame(1, DB::table($table)->count());
            $row = DB::table($table)->first();
            $this->assertSame($activityUuid, DB::table('identifiers')->where('id', $row->based_on_id)->value('value'));
            $this->assertSame($encounterUuid, DB::table('identifiers')->where('id', $row->context_id)->value('value'));
        }
        $this->assertSame('7.00', DB::table('medication_request_requests')->value('medication_qty'));
        $this->assertSame('medication_request', DB::table('medication_request_requests')->value('resource_type'));
    }
}
