<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Repositories\MedicalEvents\CodeableConceptRepository;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SharedCodingPersistenceTest extends TestCase
{
    use DatabaseTransactions {
        beginDatabaseTransaction as beginIsolatedDatabaseTransaction;
    }

    public function beginDatabaseTransaction(): void
    {
        $this->assertSame('pgsql', config('database.default'));
        $this->assertSame('testing', DB::connection()->getDatabaseName());
        $schemaName = 'coding_contract_'.bin2hex(random_bytes(8));
        config(['database.connections.pgsql.search_path' => $schemaName]);
        DB::purge('pgsql');
        $this->beginIsolatedDatabaseTransaction();
        DB::statement('CREATE SCHEMA "'.$schemaName.'"');
    }

    public function test_coding_storage_uses_only_system_and_code_without_optional_columns(): void
    {
        Schema::create('codeable_concepts', static function (Blueprint $table): void {
            $table->id();
            $table->text('text')->nullable();
            $table->timestamps();
        });
        (require database_path('migrations/install/2025_12_10_000052_create_codings_table.php'))->up();
        $repository = app(CodeableConceptRepository::class);
        $input = ['text' => 'Synthetic concept', 'coding' => [
            ['system' => 'synthetic/one', 'code' => 'A', 'display' => 'Optional label', 'version' => '1', 'userSelected' => false],
            ['system' => 'synthetic/two', 'code' => 'B', 'display' => 'Other label', 'version' => '2', 'userSelected' => true],
        ]];

        $concept = $repository->store($input);
        $repository->update($concept, $input);

        $this->assertSame(['coding' => [
            ['system' => 'synthetic/one', 'code' => 'A'],
            ['system' => 'synthetic/two', 'code' => 'B'],
        ], 'text' => 'Synthetic concept'], $repository->toResource($concept->load('coding')));
        $repository->update($concept, ['coding' => [['system' => 'synthetic/one', 'code' => 'C']]]);
        $this->assertSame('Synthetic concept', $concept->fresh()->text);
        $repository->update($concept, ['text' => null]);
        $this->assertNull($concept->fresh()->text);
        $this->assertSame(1, DB::table('codings')->count());
        $concept->load(['coding' => static fn ($query) => $query->where('code', 'B')]);
        $repository->update($concept, ['coding' => []]);
        $this->assertSame(0, DB::table('codings')->count());

        foreach (['display', 'version', 'user_selected'] as $column) {
            $this->assertFalse(Schema::hasColumn('codings', $column));
        }
    }
}
