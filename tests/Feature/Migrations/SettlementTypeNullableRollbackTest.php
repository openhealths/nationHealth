<?php

declare(strict_types=1);

namespace Tests\Feature\Migrations;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SettlementTypeNullableRollbackTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate:fresh', [
            '--path' => [
                database_path('migrations'),
                database_path('migrations/install'),
            ],
            '--realpath' => true,
        ]);
    }

    #[Test]
    public function down_backfills_null_settlement_type_before_restoring_not_null(): void
    {
        $this->assertTrue(Schema::hasTable('addresses'));

        // Install schema already allows null settlement_type (same state as after up()).
        DB::table('addresses')->insert([
            'type' => 'RESIDENCE',
            'country' => 'UA',
            'area' => 'КИЇВСЬКА',
            'settlement' => 'Київ',
            'settlement_type' => null,
            'settlement_id' => '00000000-0000-0000-0000-000000000001',
            'addressable_id' => 1,
            'addressable_type' => 'App\\Models\\Person',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $migration = require database_path(
            'migrations/update/0_1/2026_07_15_100000_make_settlement_type_nullable_on_addresses_table.php'
        );

        $migration->down();

        $this->assertSame(
            '',
            DB::table('addresses')
                ->where('settlement_id', '00000000-0000-0000-0000-000000000001')
                ->value('settlement_type')
        );

        $column = collect(Schema::getColumns('addresses'))->firstWhere('name', 'settlement_type');

        $this->assertNotNull($column);
        $this->assertFalse($column['nullable']);
    }
}
