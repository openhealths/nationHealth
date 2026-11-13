<?php

declare(strict_types=1);

namespace Tests\Feature\Composition;

use App\Models\MedicalEvents\Sql\Composition;
use App\Models\Person\Person;
use App\Repositories\MedicalEvents\CompositionRepository;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CompositionFhirMigrationTest extends TestCase
{
    use DatabaseTransactions;

    public static function migrationEntrypoints(): array
    {
        return [
            'project install' => ['database/migrations/install/2026_10_24_160000_preserve_composition_fhir_details.php'],
            'project update' => ['database/migrations/update/0_1/2026_10_24_160000_preserve_composition_fhir_details.php'],
        ];
    }

    #[DataProvider('migrationEntrypoints')]
    public function test_upgrade_preserves_existing_conclusions_and_enables_complete_import(string $path): void
    {
        $this->assertSame('pgsql', DB::connection()->getDriverName());
        $this->assertSame('testing', DB::connection()->getDatabaseName());
        Schema::drop('composition_events');
        $person = Person::create(['uuid' => (string) Str::uuid(), 'birth_date' => '1990-01-01', 'gender' => 'MALE']);
        $composition = Composition::create([
            'uuid' => (string) Str::uuid(), 'person_id' => $person->id,
            'status' => 'PRELIMINARY', 'title' => 'Existing conclusion',
        ]);
        DB::table('periods')->insert([
            'periodable_type' => Composition::class, 'periodable_id' => $composition->id,
            'start' => '2026-10-25 08:09:10.123456',
        ]);

        $migration = require base_path($path);
        $migration->up();
        app(CompositionRepository::class)->assertFhirStorageReady();
        $this->assertSame('2026-10-25T08:09:10.123456Z', $composition->fresh()->toDetail()['event'][0]['period']['start']);
        $updated = app(CompositionRepository::class)->store([
            'identifier' => ['value' => $composition->uuid],
            'date' => '2026-10-26T07:32:54.791768Z',
            'extension' => [['valueCode' => 'IS_ACCIDENT', 'valueBoolean' => false]],
            'event' => [],
        ], $person);
        $migration->up();

        $this->assertSame($composition->id, $updated->id);
        $this->assertSame('Existing conclusion', $updated->title);
        $this->assertSame(false, $updated->fresh()->isAccident);
        $this->assertSame('2026-10-26T07:32:54.791768Z', $updated->fresh()->toDetail()['date']);
        foreach ([['compositions', 'date'], ['periods', 'start'], ['periods', 'end']] as [$table, $column]) {
            $precision = DB::table('information_schema.columns')->where('table_schema', 'public')
                ->where('table_name', $table)->where('column_name', $column)->value('datetime_precision');
            $this->assertSame(6, (int) $precision);
        }
    }
}
