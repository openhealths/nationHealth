<?php

declare(strict_types=1);

namespace Tests\Feature\CarePlan;

use App\Models\MedicalEvents\Sql\Medications\MedicationRequestRequest;

use App\Repositories\MedicalEvents\Repository;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class MedicationPeriodNormalizationTest extends TestCase
{
    use DatabaseTransactions {
        beginDatabaseTransaction as beginIsolatedDatabaseTransaction;
    }

    public function beginDatabaseTransaction(): void
    {
        $this->assertSame('testing', DB::connection()->getDatabaseName());
        $schemaName = 'period_normalization_'.bin2hex(random_bytes(8));
        config(['database.connections.pgsql.search_path' => $schemaName]);
        DB::purge('pgsql');
        $this->beginIsolatedDatabaseTransaction();
        DB::statement('CREATE SCHEMA "'.$schemaName.'"');
    }

    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('identifiers', static fn (Blueprint $table) => $table->id());
        Schema::create('care_plans', static fn (Blueprint $table) => $table->id());
        Schema::create('medication_request_requests', static function (Blueprint $table): void {
            $table->id();
            foreach (['started_at', 'ended_at', 'period_start', 'period_end'] as $column) {
                $table->timestampTz($column)->nullable();
            }
        });
        (require database_path('migrations/install/2025_12_10_000066_create_periods_table.php'))->up();
    }

    public function test_partial_period_retains_both_legacy_boundaries_after_columns_are_removed(): void
    {
        DB::table('medication_request_requests')->insert(['id' => 1, 'period_start' => '2026-01-01', 'period_end' => '2026-02-01']);
        $request = MedicationRequestRequest::findOrFail(1);
        Repository::period()->sync($request, ['start' => '2026-01-01']);

        (require database_path('migrations/update/0_1/2026_10_27_160000_normalize_care_plan_references_and_medication_periods.php'))->up();

        $this->assertSame(['start' => '2026-01-01T00:00:00Z', 'end' => '2026-02-01T00:00:00Z'], Repository::period()->toResource($request->fresh()->period));
        $this->assertFalse(Schema::hasColumn('medication_request_requests', 'period_end'));
        $this->assertSame(1, DB::table('periods')->count());
    }

    public function test_conflicting_period_stops_before_legacy_columns_are_removed(): void
    {
        DB::table('medication_request_requests')->insert(['id' => 1, 'period_start' => '2026-01-02', 'period_end' => '2026-02-01']);
        $request = MedicationRequestRequest::findOrFail(1);
        Repository::period()->sync($request, ['start' => '2026-01-01']);
        try {
            (require database_path('migrations/update/0_1/2026_10_27_160000_normalize_care_plan_references_and_medication_periods.php'))->up();
            $this->fail('Conflicting clinical periods must not be discarded.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('conflicting start boundaries', $exception->getMessage());
        }
        $this->assertTrue(Schema::hasColumn('medication_request_requests', 'period_end'));
        $this->assertSame('2026-01-02 00:00:00+00', DB::table('medication_request_requests')->value('period_start'));
    }
}
