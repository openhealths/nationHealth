<?php

declare(strict_types=1);

namespace Tests\Feature\Composition;

use App\Enums\Composition\CompositionAsyncOperation;
use App\Enums\Composition\CompositionExtension;
use App\Enums\Composition\CompositionType;
use App\Enums\JobStatus;
use App\Models\EhealthJob;
use App\Models\MedicalEvents\Sql\Composition;
use App\Models\Person\Person;
use App\Repositories\MedicalEvents\CompositionRepository;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class CompositionStorageMigrationTest extends TestCase
{
    use RefreshCompositionDatabase;

    public function test_fresh_install_has_typed_clinical_fields_and_separate_technical_records(): void
    {
        foreach (['extension', 'data', 'async_job_id', 'async_job_status', 'erln_status'] as $column) {
            $this->assertFalse(Schema::hasColumn('compositions', $column));
        }
        foreach (CompositionExtension::cases() as $field) {
            $this->assertTrue(Schema::hasColumn('compositions', $field->column()));
        }
        $this->assertTrue(Schema::hasTable('composition_operations'));
        $this->assertTrue(Schema::hasTable('composition_integrations'));

        (require database_path('migrations/update/0_1/2026_10_01_150000_normalize_composition_storage.php'))->up();
        $this->assertSame(0, Composition::count());
    }

    public function test_upgrade_preserves_clinical_fields_integrations_and_pending_jobs(): void
    {
        $composition = $this->legacyComposition();
        Schema::table('compositions', static function (Blueprint $table): void {
            $table->dropColumn(array_map(static fn ($field) => $field->column(), CompositionExtension::cases()));
        });
        DB::table('compositions')->where('id', $composition->id)->update([
            'extension' => json_encode([
                ['valueCode' => 'INFORM_WITH', 'valueUuid' => 'aaaaaaaa-0000-4000-8000-000000000001'],
                ['valueCode' => 'IS_ACCIDENT', 'valueBoolean' => true],
                ['valueCode' => 'TREATMENT_VIOLATION_DATE', 'valueDate' => '2026-09-20'],
                ['valueCode' => 'NEWBORN_BIRTH_DATE', 'valueDate' => '2026-09-19'],
                ['valueCode' => 'NEWBORN_SEX', 'valueString' => 'MALE'],
            ]),
            'data' => json_encode(['_integration' => [
                ['component' => 'ERLN', 'type' => 'CREATE_ERLN_RECORD', 'integrationStatus' => 'ERROR',
                    'details' => ['SL_NUM' => '1234'], 'statusMessage' => 'Pending retry'],
                ['component' => 'DRACS', 'type' => 'CHECK_DRACS', 'taskStatus' => 'SUCCESS'],
            ]]),
            'async_job_id' => 'legacy-job', 'async_job_status' => 'PENDING', 'async_job_operation' => 'SIGN',
        ]);

        $migration = require database_path('migrations/2026_10_01_150000_normalize_composition_storage.php');
        $migration->up();
        $migration->up();
        $composition->refresh();
        $this->assertTrue($composition->isAccident);
        $this->assertFalse($composition->isIntoxicated);
        $this->assertSame('aaaaaaaa-0000-4000-8000-000000000001', $composition->informWithUuid);
        $this->assertSame('2026-09-20', $composition->treatmentViolationDate->format('Y-m-d'));
        $this->assertSame('2026-09-19', $composition->newbornBirthDate->format('Y-m-d'));
        $this->assertSame('MALE', $composition->newbornSex);
        $this->assertSame('Local conclusion', $composition->toDetail()['title']);
        $this->assertCount(2, $composition->integrations);
        $this->assertSame('1234', $composition->erlnRecordNumber);
        $this->assertSame('ERROR', $composition->erlnStatus);
        $this->assertSame(CompositionAsyncOperation::SIGN, $composition->latestOperation->operation);
        $this->assertSame(CompositionType::NEWBORN, $composition->latestOperation->compositionType);
        $this->assertSame('legacy-job', $composition->latestOperation->remoteJobId);
        $this->assertSame(JobStatus::PENDING->value, $composition->latestOperation->job->status);
        $this->assertSame($composition->encounterUuid, $composition->latestOperation->encounterUuid);
        $this->assertSame(1, EhealthJob::count());
        $this->assertFalse(Schema::hasColumn('compositions', 'data'));
        $this->assertFalse(Schema::hasColumn('compositions', 'async_job_id'));
    }

    public function test_upgrade_preserves_existing_typed_values_and_failed_job_errors(): void
    {
        $composition = $this->legacyComposition();
        DB::table('compositions')->where('id', $composition->id)->update([
            'is_accident' => true, 'newborn_sex' => 'FEMALE',
            'erln_status' => 'ERROR', 'erln_record_number' => '5678',
            'async_job_id' => 'failed-job', 'async_job_status' => 'FAILED',
            'async_job_operation' => 'ERLN_RETRY', 'async_job_error' => 'Integration rejected',
        ]);
        (require database_path('migrations/2026_10_01_150000_normalize_composition_storage.php'))->up();
        $composition->refresh();
        $this->assertTrue($composition->isAccident);
        $this->assertSame('FEMALE', $composition->newbornSex);
        $this->assertSame('5678', $composition->erlnRecordNumber);
        $this->assertSame('FAILED', $composition->latestOperation->status);
        $this->assertSame('Integration rejected', $composition->latestOperation->error);
    }

    public function test_invalid_legacy_json_prevents_dropping_the_source_columns(): void
    {
        $composition = $this->legacyComposition();
        // A text column emulates a damaged legacy payload without the DB rejecting it first.
        Schema::table('compositions', static function (Blueprint $table): void {
            $table->dropColumn('data');
        });
        Schema::table('compositions', static function (Blueprint $table): void {
            $table->text('data')->nullable();
        });
        DB::table('compositions')->where('id', $composition->id)->update(['data' => '{invalid']);
        try {
            (require database_path('migrations/2026_10_01_150000_normalize_composition_storage.php'))->up();
            $this->fail('Damaged JSON must stop the upgrade before data is dropped.');
        } catch (\JsonException) {
            $this->assertTrue(Schema::hasColumn('compositions', 'extension'));
            $this->assertTrue(Schema::hasColumn('compositions', 'data'));
            $this->assertSame(0, EhealthJob::count());
        }
    }

    private function legacyComposition(): Composition
    {
        $person = Person::create(['uuid' => (string) Str::uuid(), 'birth_date' => '2000-01-01', 'gender' => 'FEMALE']);
        $composition = app(CompositionRepository::class)->store([
            'identifier' => ['value' => (string) Str::uuid()], 'status' => 'PRELIMINARY', 'title' => 'Local conclusion',
            'type' => ['coding' => [['system' => 'COMPOSITION_TYPES', 'code' => 'NEWBORN']]],
            'encounter' => ['value' => (string) Str::uuid()], 'author' => ['value' => (string) Str::uuid()],
        ], $person, (string) Str::uuid());
        Schema::table('compositions', static function (Blueprint $table): void {
            $table->json('extension')->nullable();
            $table->json('data')->nullable();
            $table->string('async_job_id')->nullable();
            $table->string('async_job_status')->nullable();
            $table->string('async_job_operation')->nullable();
            $table->text('async_job_error')->nullable();
            $table->string('erln_status')->nullable();
            $table->string('erln_record_number')->nullable();
            $table->text('erln_status_message')->nullable();
        });

        return $composition;
    }
}
