<?php

declare(strict_types=1);

namespace Tests\Integration\Database;

use App\Enums\Declaration\Status as DeclarationStatus;
use App\Jobs\ObservationConfigurationSync;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/** Run explicitly against a disposable testing database; each test rebuilds its schema. */
class MigrationSchemaParityTest extends TestCase
{
    protected const string ALIGNMENT = '2026_11_24_000000_align_install_and_update_constraints.php';

    protected function setUp(): void
    {
        parent::setUp();

        if (getenv('MIS_TEST_MIGRATION_PARITY') !== '1') {
            $this->markTestSkipped('Set MIS_TEST_MIGRATION_PARITY=1 only for a disposable testing database.');
        }
        $this->assertTrue($this->app->environment('testing'));
        $this->assertSame('testing', DB::connection()->getDatabaseName());
        $this->assertContains(DB::getDriverName(), ['pgsql', 'mysql', 'mariadb']);

        Schema::dropAllTables();
    }

    public function test_install_full_update_and_repeated_update_have_identical_schemas(): void
    {
        $migrator = $this->app->make('migrator');
        $migrator->getRepository()->createRepository();
        $migrator->run([database_path('migrations/install')]);
        $installed = $this->snapshot();

        Bus::fake([ObservationConfigurationSync::class]);
        $migrator->run([database_path('migrations/update/0_1')]);
        $this->assertSchemasMatch($installed, $this->snapshot());

        $migrator->run([database_path('migrations/update/0_1')]);
        $this->assertSchemasMatch($installed, $this->snapshot());

        foreach ([
            '2026_06_02_000000_add_draft_status_to_episodes_table.php' => ['episodes', 'episode_status_history'],
            '2026_06_08_114019_add_draft_status_to_diagnostic_reports_table.php' => ['diagnostic_reports'],
            '2026_06_29_000000_add_draft_status_to_prepersons_table.php' => ['prepersons'],
            '2026_07_14_000000_update_declarations_status_constraint.php' => ['declarations'],
            '2026_07_22_120000_add_cancelled_status_to_merge_requests_table.php' => ['merge_requests'],
            '2026_07_24_120000_add_expired_status_to_merge_requests_table.php' => ['merge_requests'],
            '2026_07_27_162231_add_draft_status_to_encounters_table.php' => ['encounters'],
            '2026_09_28_120000_add_draft_status_to_specimens_table.php' => ['specimens'],
            '2026_10_13_000000_add_draft_status_to_procedures_table.php' => ['procedures'],
        ] as $file => $tables) {
            $migration = require database_path('migrations/update/0_1/'.$file);
            $migration->down();
            $migration->up();
            $this->assertSchemasMatch(array_intersect_key($installed, array_flip($tables)), $this->snapshot($tables));
        }
        $this->update('2026_10_02_130510_update_device_dispenses_for_sync.php');
        $this->assertSchemasMatch(
            array_intersect_key($installed, array_flip(['device_dispenses', 'legal_entities'])),
            $this->snapshot(['device_dispenses', 'legal_entities'])
        );
    }

    public function test_reorganization_upgrade_matches_install_and_preserves_existing_rows(): void
    {
        $this->createReferences(['legal_entities', 'employees', 'parties', 'persons', 'declarations', 'declaration_requests']);
        $install = require database_path('migrations/install/2026_05_11_153744_create_reorganization_employee_declarations_table.php');
        $install->up();
        $expected = $this->snapshot(['reorganization_employee_declarations']);
        $install->down();

        $this->update('2026_05_11_153744_create_reorganization_employee_declarations_table.php');
        // Reproduce the already deployed shape before the id/request-column migration.
        Schema::table('reorganization_employee_declarations', static function (Blueprint $table): void {
            if (Schema::hasColumn('reorganization_employee_declarations', 'id')) {
                $table->dropColumn('id');
            }
            if (Schema::hasColumn('reorganization_employee_declarations', 'declaration_request_id')) {
                $table->dropConstrainedForeignId('declaration_request_id');
                $table->dropColumn('declaration_request_uuid');
            }
        });
        $row = [
            'legal_entity_uuid' => (string) Str::uuid(),
            'employee_uuid' => (string) Str::uuid(),
            'party_uuid' => (string) Str::uuid(),
        ];
        DB::table('reorganization_employee_declarations')->insert($row);
        $this->update('2026_05_19_112112_add_declaration_request_to_reorganization_employee_declarations_table.php');
        $this->update(self::ALIGNMENT);
        $this->assertSchemasMatch($expected, $this->snapshot(['reorganization_employee_declarations']));
        $saved = DB::table('reorganization_employee_declarations')->sole();
        $this->assertNotNull($saved->id);
        foreach ($row as $column => $value) {
            $this->assertSame($value, $saved->{$column});
        }
        if (DB::getDriverName() !== 'pgsql') {
            $this->assertSame('id', Schema::getColumns('reorganization_employee_declarations')[0]['name']);
        }
    }

    public function test_episode_upgrade_matches_install_and_preserves_existing_rows(): void
    {
        $this->createReferences(['persons', 'prepersons', 'encounters', 'codings', 'identifiers', 'codeable_concepts']);
        (require database_path('migrations/install/2025_12_10_000060_create_episodes_table.php'))->up();
        $tables = ['episodes', 'episode_current_diagnoses', 'episode_diagnoses_history', 'episode_diagnoses_history_items', 'episode_status_history'];
        $expected = $this->snapshot($tables);
        foreach (['episode_status_history', 'episode_diagnoses_history_items', 'episode_diagnoses_history', 'episode_current_diagnoses'] as $table) {
            Schema::drop($table);
        }
        Schema::table('episodes', static function (Blueprint $table): void {
            $table->dropConstrainedForeignId('status_reason_id');
            $table->dropColumn(['closing_summary', 'explanatory_letter']);
        });
        $uuid = (string) Str::uuid();
        $id = DB::table('episodes')->insertGetId(['uuid' => $uuid, 'status' => 'active', 'name' => 'Before upgrade']);
        $this->update('2026_04_22_000000_update_episodes_table_add_missing_columns.php');
        $this->update(self::ALIGNMENT);
        $this->assertSchemasMatch($expected, $this->snapshot($tables));
        $this->assertSame($uuid, DB::table('episodes')->where('id', $id)->value('uuid'));
        $this->assertSame('Before upgrade', DB::table('episodes')->where('id', $id)->value('name'));
    }

    public function test_legacy_declaration_statuses_are_converted_before_the_constraint_is_narrowed(): void
    {
        Schema::create('declarations', static function (Blueprint $table): void {
            $table->id();
            $table->enum('status', ['DRAFT', 'NEW', 'APPROVED', 'SIGNED', 'active', 'terminated', 'REJECTED', 'CANCELLED']);
        });
        foreach (['REJECTED', 'CANCELLED', 'active', 'terminated'] as $status) {
            DB::table('declarations')->insert(['status' => $status]);
        }
        $this->update('2026_07_14_000000_update_declarations_status_constraint.php');
        $this->assertSame(['rejected', 'closed', 'active', 'terminated'], DB::table('declarations')->orderBy('id')->pluck('status')->all());
        $first = $this->snapshot(['declarations']);
        $this->update('2026_07_14_000000_update_declarations_status_constraint.php');
        $this->assertSchemasMatch($first, $this->snapshot(['declarations']));
        foreach (DeclarationStatus::values() as $status) {
            DB::table('declarations')->insert(['status' => $status]);
        }
        $this->assertSame(4 + count(DeclarationStatus::values()), DB::table('declarations')->count());
    }

    public function test_paper_referral_date_upgrade_matches_install_and_preserves_dates_and_nulls(): void
    {
        (require database_path('migrations/install/2025_12_10_000078_create_paper_referrals_table.php'))->up();
        $expected = $this->snapshot(['paper_referrals']);
        $migration = require database_path('migrations/update/0_1/2026_05_25_000000_change_service_request_date_column_in_paper_referrals_table.php');
        $migration->down();
        foreach (['2026-05-25 12:30:00', null] as $date) {
            DB::table('paper_referrals')->insert([
                'requester_legal_entity_edrpou' => '12345678',
                'requester_employee_name' => 'Migration test',
                'service_request_date' => $date,
                'paper_referralable_type' => 'migration-test',
                'paper_referralable_id' => 1,
            ]);
        }
        $migration->up();
        $this->assertSchemasMatch($expected, $this->snapshot(['paper_referrals']));
        $this->assertSame(['2026-05-25 12:30:00', null], DB::table('paper_referrals')->orderBy('id')->pluck('service_request_date')->all());
    }

    public function test_unsupported_legacy_declaration_statuses_stop_before_schema_or_data_changes(): void
    {
        Schema::create('declarations', static function (Blueprint $table): void {
            $table->id();
            $table->enum('status', ['DRAFT', 'REJECTED', 'CANCELLED']);
        });
        DB::table('declarations')->insert(['status' => 'DRAFT']);
        $before = $this->snapshot(['declarations']);
        try {
            $this->update('2026_07_14_000000_update_declarations_status_constraint.php');
            $this->fail('Unsupported legacy statuses must be rejected before changing the schema.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Unsupported legacy declaration statuses', $exception->getMessage());
        }
        $this->assertSchemasMatch($before, $this->snapshot(['declarations']));
        $this->assertSame('DRAFT', DB::table('declarations')->sole()->status);
    }

    public function test_previously_applied_constraint_names_and_comments_converge_to_install(): void
    {
        $this->createReferences(['legal_entities', 'employees', 'parties', 'persons', 'declarations', 'declaration_requests', 'prepersons', 'encounters', 'codings', 'identifiers', 'codeable_concepts']);
        (require database_path('migrations/install/2026_05_11_153744_create_reorganization_employee_declarations_table.php'))->up();
        (require database_path('migrations/install/2025_12_10_000060_create_episodes_table.php'))->up();
        $this->createReferences(['care_plans', 'care_plan_activities']);
        $pivots = [
            'care_plan_contributors' => ['care_plan_id', 'care_plans', 'identifier_id', 'identifiers'],
            'care_plan_activity_authors' => ['activity_id', 'care_plan_activities', 'identifier_id', 'identifiers'],
            'care_plan_activity_reason_codes' => ['activity_id', 'care_plan_activities', 'concept_id', 'codeable_concepts'],
            'care_plan_activity_goal_concepts' => ['activity_id', 'care_plan_activities', 'concept_id', 'codeable_concepts'],
            'care_plan_activity_outcome_concepts' => ['activity_id', 'care_plan_activities', 'concept_id', 'codeable_concepts'],
        ];
        foreach ($pivots as $name => [$owner, $ownerTable, $value, $valueTable]) {
            Schema::create($name, static function (Blueprint $table) use ($name, $owner, $ownerTable, $value, $valueTable): void {
                $table->id();
                $table->foreignId($owner)->constrained($ownerTable)->cascadeOnDelete();
                $table->foreignId($value)->constrained($valueTable);
                $table->unsignedInteger('position');
                $table->unique([$owner, $value], $name.'_owner_value_unique');
            });
        }
        Schema::create('quantities', static function (Blueprint $table): void {
            $table->id();
            $table->decimal('value', 24, 10)->comment('Numerical value (with implicit precision)');
        });
        Schema::create('client_connections', static fn (Blueprint $table) => $table->uuid()->comment('Connection UUID at the eHealth side'));
        Schema::create('merged_persons', static function (Blueprint $table): void {
            $table->uuid('merged_uuid')->comment('eHealth identifier of the person or preperson merged into the identified patient');
            $table->foreignId('merged_preperson_id')->nullable()->comment('Preperson merged into the patient, when their record is known locally')->constrained('prepersons');
        });
        $expected = $this->snapshot();
        $quantityId = DB::table('quantities')->insertGetId(['value' => '123456789.1234567890']);
        foreach ([
            ['episode_diagnoses_history_items', 'episode_diagnoses_history_id', 'episode_diagnoses_history', 'episode_diagnoses_history_items_history_fk'],
            ['reorganization_employee_declarations', 'declaration_request_id', 'declaration_requests', 'reorg_employee_declarations_request_fk'],
        ] as [$table, $column, $target, $name]) {
            $legacy = substr($table.'_'.$column.'_foreign', 0, DB::getDriverName() === 'pgsql' ? 63 : 64);
            if (DB::getDriverName() === 'pgsql') {
                DB::statement('ALTER TABLE '.$table.' RENAME CONSTRAINT '.$name.' TO '.$legacy);
            } else {
                Schema::table($table, static function (Blueprint $blueprint) use ($column, $target, $name, $legacy): void {
                    $blueprint->dropForeign($name);
                    $blueprint->foreign($column, $legacy)->references('id')->on($target)->cascadeOnDelete();
                });
                // Some engines replace the implicit supporting index when the FK is recreated.
                if (Schema::hasIndex($table, $name) && !Schema::hasIndex($table, $legacy)) {
                    Schema::table($table, static fn (Blueprint $blueprint) => $blueprint->renameIndex($name, $legacy));
                }
            }
        }
        Schema::table('reorganization_employee_declarations', static function (Blueprint $table): void {
            foreach (['person_id' => 'persons', 'declaration_id' => 'declarations'] as $column => $target) {
                $table->dropForeign([$column]);
                $table->foreign($column)->references('id')->on($target)->cascadeOnDelete();
            }
        });
        foreach ($pivots as $name => [$owner, $ownerTable, $value]) {
            $legacy = substr($name.'_'.$owner.'_'.$value.'_unique', 0, DB::getDriverName() === 'pgsql' ? 63 : 64);
            Schema::table($name, static fn (Blueprint $table) => $table->renameIndex($name.'_owner_value_unique', $legacy));
        }
        Schema::table('quantities', static fn (Blueprint $table) => $table->decimal('value', 24, 10)->change());
        Schema::table('client_connections', static fn (Blueprint $table) => $table->uuid()->comment('Client UUID at the eHealth side')->change());
        Schema::table('merged_persons', static function (Blueprint $table): void {
            $table->uuid('merged_uuid')->change();
            $table->foreignId('merged_preperson_id')->nullable()->change();
        });
        $this->update(self::ALIGNMENT);
        $this->assertSchemasMatch($expected, $this->snapshot());
        $this->update(self::ALIGNMENT);
        $this->assertSchemasMatch($expected, $this->snapshot());
        $this->assertSame('123456789.1234567890', (string) DB::table('quantities')->where('id', $quantityId)->value('value'));
    }

    protected function createReferences(array $tables): void
    {
        foreach ($tables as $name) {
            Schema::create($name, static fn (Blueprint $table) => $table->id());
        }
    }

    protected function update(string $file): void
    {
        (require database_path('migrations/update/0_1/'.$file))->up();
    }

    protected function snapshot(?array $tables = null): array
    {
        $connection = DB::connection();
        $result = [];
        $details = collect(Schema::getTables())->keyBy('name');
        $tables ??= Schema::getTableListing();
        sort($tables);
        foreach ($tables as $table) {
            $name = str_contains($table, '.') ? substr($table, strrpos($table, '.') + 1) : $table;
            if ($name === 'migrations') {
                continue;
            }
            // Physical column order is not part of the contract; PostgreSQL appends added columns.
            $columns = Schema::getColumns($table);
            $indexes = Schema::getIndexes($table);
            $foreignKeys = Schema::getForeignKeys($table);
            $byName = static fn (array $a, array $b): int => strcmp($a['name'], $b['name']);
            usort($columns, $byName);
            usort($indexes, $byName);
            usort($foreignKeys, $byName);
            $checks = DB::getDriverName() === 'pgsql'
                ? $connection->select("SELECT conname AS name, pg_get_constraintdef(oid) AS definition FROM pg_constraint WHERE conrelid = ?::regclass AND contype = 'c' ORDER BY conname", [$table])
                : $connection->select('SELECT tc.CONSTRAINT_NAME AS name, cc.CHECK_CLAUSE AS definition FROM information_schema.TABLE_CONSTRAINTS tc JOIN information_schema.CHECK_CONSTRAINTS cc ON tc.CONSTRAINT_SCHEMA = cc.CONSTRAINT_SCHEMA AND tc.CONSTRAINT_NAME = cc.CONSTRAINT_NAME WHERE tc.TABLE_SCHEMA = DATABASE() AND tc.TABLE_NAME = ? AND tc.CONSTRAINT_TYPE = ? ORDER BY tc.CONSTRAINT_NAME', [$name, 'CHECK']);
            $result[$name] = [
                'table' => array_intersect_key($details->get($name, []), array_flip(['comment', 'collation', 'engine'])),
                'columns' => $columns,
                'indexes' => $indexes,
                'foreign_keys' => $foreignKeys,
                'checks' => array_map(static fn (object $check): array => (array) $check, $checks),
            ];
        }

        return $result;
    }

    protected function assertSchemasMatch(array $expected, array $actual): void
    {
        ksort($expected);
        ksort($actual);
        $this->assertSame(array_keys($expected), array_keys($actual), 'The table sets must match.');
        foreach ($expected as $table => $schema) {
            $this->assertSame($schema, $actual[$table], 'Schema mismatch for '.$table);
        }
    }
}
