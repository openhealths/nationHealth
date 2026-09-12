<?php

declare(strict_types=1);

use App\Models\MedicalEvents\Sql\CodeableConcept;
use App\Models\MedicalEvents\Sql\Coding;
use App\Repositories\MedicalEvents\Repository;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Convert Medication/Service/Device request refs to FHIR Identifiers and
 * intent/category/priority to Coding / CodeableConcept FKs.
 *
 * Also adds medication `source` and makes medication `employee_id` nullable
 * so eHealth-upserted prescriptions can be stored without a local author.
 */
return new class extends Migration
{
    private const INTENT_SYSTEM = 'http://hl7.org/fhir/request-intent';

    public function up(): void
    {
        $this->migrateRequestTable('medication_request_requests', isMedication: true);
        $this->migrateRequestTable('service_request_requests');
        $this->migrateRequestTable('device_request_requests');
    }

    public function down(): void
    {
        // Irreversible data reshape (identifier substitution + dropped strings).
    }

    private function migrateRequestTable(string $table, bool $isMedication = false): void
    {
        if (!Schema::hasTable($table)) {
            return;
        }

        if ($isMedication) {
            $this->ensureMedicationSourceAndNullableEmployee($table);
        }

        $needsFhirShape = Schema::hasColumn($table, 'intent') && !Schema::hasColumn($table, 'intent_id');
        if (!$needsFhirShape) {
            // Ensure based_on/context point at identifiers when columns already look FHIR-shaped.
            return;
        }

        Schema::table($table, static function (Blueprint $blueprint) {
            $blueprint->foreignId('intent_id')->nullable()->constrained('codings');
            $blueprint->foreignId('category_id')->nullable()->constrained('codeable_concepts');
            $blueprint->foreignId('priority_id')->nullable()->constrained('codeable_concepts');
        });

        // Drop legacy FKs so based_on_id / context_id can hold identifier ids.
        $this->dropForeignIfExists($table, 'based_on_id');
        $this->dropForeignIfExists($table, 'context_id');

        $rows = DB::table($table)->select(['id', 'intent', 'category', 'priority', 'based_on_id', 'context_id'])->get();

        foreach ($rows as $row) {
            $intentId = null;
            if (!empty($row->intent)) {
                $intentId = Coding::firstOrCreate([
                    'code' => (string) $row->intent,
                    'system' => self::INTENT_SYSTEM,
                ])->id;
            }

            $categoryId = null;
            if (!empty($row->category)) {
                $categoryId = CodeableConcept::create(['text' => (string) $row->category])->id;
            }

            $priorityId = null;
            if (!empty($row->priority)) {
                $priorityId = CodeableConcept::create(['text' => (string) $row->priority])->id;
            }

            $basedOnId = null;
            if (!empty($row->based_on_id)) {
                $activityUuid = DB::table('care_plan_activities')->where('id', $row->based_on_id)->value('uuid');
                if ($activityUuid) {
                    $basedOnId = Repository::identifier()->store((string) $activityUuid)->id;
                }
            }

            $contextId = null;
            if (!empty($row->context_id)) {
                $encounterUuid = DB::table('encounters')->where('id', $row->context_id)->value('uuid');
                if ($encounterUuid) {
                    $contextId = Repository::identifier()->store((string) $encounterUuid)->id;
                }
            }

            DB::table($table)->where('id', $row->id)->update([
                'intent_id' => $intentId,
                'category_id' => $categoryId,
                'priority_id' => $priorityId,
                'based_on_id' => $basedOnId,
                'context_id' => $contextId,
            ]);
        }

        Schema::table($table, static function (Blueprint $blueprint) {
            $blueprint->dropColumn(['intent', 'category', 'priority']);
        });

        Schema::table($table, static function (Blueprint $blueprint) {
            $blueprint->foreign('based_on_id')->references('id')->on('identifiers');
            $blueprint->foreign('context_id')->references('id')->on('identifiers');
        });
    }

    private function ensureMedicationSourceAndNullableEmployee(string $table): void
    {
        if (!Schema::hasColumn($table, 'source')) {
            Schema::table($table, static function (Blueprint $blueprint) {
                $blueprint->string('source')->default('local')->after('ehealth_payload');
            });
        }

        // Allow eHealth upserts without a local employee author.
        $employee = collect(Schema::getColumns($table))->firstWhere('name', 'employee_id');
        if ($employee !== null && ($employee['nullable'] ?? false) === false) {
            $this->dropForeignIfExists($table, 'employee_id');
            Schema::table($table, static function (Blueprint $blueprint) {
                $blueprint->unsignedBigInteger('employee_id')->nullable()->change();
            });
            Schema::table($table, static function (Blueprint $blueprint) {
                $blueprint->foreign('employee_id')->references('id')->on('employees');
            });
        }
    }

    private function dropForeignIfExists(string $table, string $column): void
    {
        $foreign = collect(Schema::getForeignKeys($table))
            ->first(static fn (array $fk): bool => in_array($column, $fk['columns'], true));

        if ($foreign === null) {
            return;
        }

        Schema::table($table, static function (Blueprint $blueprint) use ($foreign) {
            $blueprint->dropForeign($foreign['name']);
        });
    }
};
