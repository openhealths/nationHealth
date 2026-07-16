<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up(): void
    {
        if (!Schema::hasTable('person_names')) {
            Schema::create('person_names', static function (Blueprint $table): void {
                $table->id();
                $table->foreignId('person_id')->constrained('persons')->cascadeOnDelete();
                $table->string('language');
                $table->string('first_name');
                $table->string('last_name')->nullable();
                $table->string('second_name')->nullable();
                $table->boolean('no_last_name')->default(false);
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('person_request_names')) {
            Schema::create('person_request_names', static function (Blueprint $table): void {
                $table->id();
                $table->foreignId('person_request_id')->constrained('person_requests')->cascadeOnDelete();
                $table->string('language');
                $table->string('first_name');
                $table->string('last_name')->nullable();
                $table->string('second_name')->nullable();
                $table->boolean('no_last_name')->default(false);
                $table->timestamps();
            });
        }

        $this->backfillNames();

        foreach (['person_requests', 'persons'] as $tableName) {
            $columns = array_filter(
                ['first_name', 'last_name', 'second_name'],
                static fn (string $column): bool => Schema::hasColumn($tableName, $column)
            );

            if (!empty($columns)) {
                Schema::table($tableName, static function (Blueprint $table) use ($columns): void {
                    $table->dropColumn($columns);
                });
            }
        }
    }

    /**
     * Copy the flat name columns into the related names tables before they are dropped.
     *
     * Existing records carry no language, so they are backfilled as Ukrainian. The no_last_name flag
     * has no source column and is derived from the stored last name, the same way the application reads it.
     *
     * @return void
     */
    private function backfillNames(): void
    {
        $tables = [
            'persons' => ['person_names', 'person_id'],
            'person_requests' => ['person_request_names', 'person_request_id']
        ];

        $timestamp = CarbonImmutable::now();

        foreach ($tables as $sourceTable => [$namesTable, $foreignKey]) {
            if (!Schema::hasColumn($sourceTable, 'first_name')) {
                continue;
            }

            DB::table($sourceTable)
                ->select(['id', 'first_name', 'last_name', 'second_name'])
                ->orderBy('id')
                ->chunk(500, static function (Collection $records) use ($namesTable, $foreignKey, $timestamp): void {
                    $names = $records
                        ->filter(static fn (object $record): bool => filled($record->first_name))
                        ->map(static fn (object $record): array => [
                            $foreignKey => $record->id,
                            'language' => 'uk',
                            'first_name' => $record->first_name,
                            'last_name' => $record->last_name,
                            'second_name' => $record->second_name,
                            'no_last_name' => blank($record->last_name),
                            'created_at' => $timestamp,
                            'updated_at' => $timestamp
                        ])
                        ->values()
                        ->all();

                    if (!empty($names)) {
                        DB::table($namesTable)->insert($names);
                    }
                });
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down(): void
    {
        foreach (['person_requests', 'persons'] as $tableName) {
            Schema::table($tableName, static function (Blueprint $table) use ($tableName): void {
                if (!Schema::hasColumn($tableName, 'first_name')) {
                    $table->string('first_name')->nullable();
                }

                if (!Schema::hasColumn($tableName, 'last_name')) {
                    $table->string('last_name')->nullable();
                }

                if (!Schema::hasColumn($tableName, 'second_name')) {
                    $table->string('second_name')->nullable();
                }
            });
        }

        Schema::dropIfExists('person_request_names');
        Schema::dropIfExists('person_names');
    }
};
