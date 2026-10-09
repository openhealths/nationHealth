<?php

declare(strict_types=1);

use App\Enums\Composition\CompositionExtension;
use App\Enums\JobStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('compositions')) {
            return;
        }
        (require __DIR__ . '/install/2026_08_07_210752_create_composition_storage_tables.php')->up();
        foreach (CompositionExtension::cases() as $field) {
            if (Schema::hasColumn('compositions', $field->column())) {
                continue;
            }
            Schema::table('compositions', static function (Blueprint $table) use ($field): void {
                match ($field->valueKey()) {
                    'valueBoolean' => $table->boolean($field->column())->default(false),
                    'valueDate' => $table->date($field->column())->nullable(),
                    'valueUuid' => $table->uuid($field->column())->nullable(),
                    default => $table->string($field->column())->nullable(),
                };
            });
        }
        $legacy = array_values(array_filter([
            'extension', 'data', 'async_job_id', 'async_job_status', 'async_job_operation', 'async_job_error',
            'erln_status', 'erln_record_number', 'erln_status_message',
        ], static fn (string $column): bool => Schema::hasColumn('compositions', $column)));
        if ($legacy === []) {
            return;
        }
        DB::transaction(function () use ($legacy): void {
            DB::table('compositions')->orderBy('id')->chunkById(100, function ($rows): void {
                foreach ($rows as $row) {
                    $this->transfer($row);
                }
            });
            Schema::table('compositions', static fn (Blueprint $table) => $table->dropColumn($legacy));
        });
    }

    private function transfer(object $row): void
    {
        $data = json_decode($row->data ?? '{}', true, 512, JSON_THROW_ON_ERROR) ?? [];
        $extensions = json_decode($row->extension ?? 'null', true, 512, JSON_THROW_ON_ERROR)
            ?? ($data['extension'] ?? []);
        $byCode = collect($extensions)->keyBy('valueCode');
        $attributes = [];
        foreach (CompositionExtension::cases() as $field) {
            $value = data_get($byCode->get($field->value), $field->valueKey());
            $attributes[$field->column()] = $byCode->has($field->value)
                ? ($field->valueKey() === 'valueBoolean' ? (bool) $value : $value)
                : ($row->{$field->column()} ?? ($field->valueKey() === 'valueBoolean' ? false : null));
        }
        DB::table('compositions')->where('id', $row->id)->update($attributes);

        $items = $data['_integration'] ?? [];
        if ($items === [] && !empty($row->erln_status)) {
            $items = [[
                'component' => 'ERLN', 'type' => 'CREATE_ERLN_RECORD', 'integrationStatus' => $row->erln_status,
                'details' => ['SL_NUM' => $row->erln_record_number ?? null], 'statusMessage' => $row->erln_status_message ?? null,
            ]];
        }
        foreach ($items as $item) {
            DB::table('composition_integrations')->insert([
                'composition_id' => $row->id,
                'component' => $item['component'], 'type' => $item['type'],
                'integration_status' => $item['integrationStatus'] ?? null,
                'task_status' => $item['taskStatus'] ?? null,
                'record_number' => data_get($item, 'details.SL_NUM'),
                'status_message' => $item['statusMessage'] ?? null,
                'ehealth_updated_at' => $item['updatedAt'] ?? null,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        if (empty($row->async_job_id)) {
            return;
        }
        $jobId = DB::table('ehealth_jobs')->insertGetId([
            'processing_method' => 'ASYNC',
            'status' => match ($row->async_job_status ?? 'PENDING') {
                'DONE' => JobStatus::COMPLETED->value,
                'FAILED' => JobStatus::FAILED->value,
                default => JobStatus::PENDING->value,
            },
            'response_data' => json_encode([
                'id' => $row->async_job_id, 'status' => $row->async_job_status ?? 'PENDING',
                'errors' => empty($row->async_job_error) ? [] : [$row->async_job_error],
            ], JSON_THROW_ON_ERROR),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $identifier = static fn (?int $id): ?string => $id ? DB::table('identifiers')->where('id', $id)->value('value') : null;
        DB::table('composition_operations')->insert([
            'composition_id' => $row->id, 'ehealth_job_id' => $jobId, 'remote_job_id' => $row->async_job_id,
            'operation' => $row->async_job_operation ?? 'CREATE',
            'composition_type' => DB::table('codings')->where('codeable_id', $row->type_id)
                ->where('codeable_type', \App\Models\MedicalEvents\Sql\CodeableConcept::class)->value('code'),
            'person_id' => $row->person_id, 'preperson_id' => $row->preperson_id,
            'encounter_uuid' => $identifier($row->encounter_id),
            'episode_uuid' => $identifier($row->episode_of_care_id),
            'author_uuid' => $identifier($row->author_id),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        // Data-preserving normalization is forward-only; reverting needs an explicit data migration.
    }
};
