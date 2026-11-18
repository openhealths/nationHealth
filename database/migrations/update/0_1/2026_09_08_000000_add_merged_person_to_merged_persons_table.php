<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Let a merged person be an identified person as well as a preperson, and keep their eHealth identifier so the ones
     * whose record is not known locally yet are stored instead of being dropped.
     *
     * @return void
     */
    public function up(): void
    {
        if (!Schema::hasTable('merged_persons')) {
            return;
        }

        if (Schema::hasColumn('merged_persons', 'merge_person_id')) {
            Schema::table('merged_persons', static function (Blueprint $table) {
                $table->renameColumn('merge_person_id', 'merged_preperson_id');
            });
        }

        Schema::table('merged_persons', static function (Blueprint $table) {
            if (!Schema::hasColumn('merged_persons', 'merged_uuid')) {
                $table->uuid('merged_uuid')
                    ->nullable()
                    ->index()
                    ->after('person_id')
                    ->comment('eHealth identifier of the person or preperson merged into the identified patient');
            }

            if (!Schema::hasColumn('merged_persons', 'merged_person_id')) {
                $table->foreignId('merged_person_id')
                    ->nullable()
                    ->after('merged_uuid')
                    ->comment('Identified person merged into the patient, when their record is known locally')
                    ->constrained('persons');
            }
        });

        $this->backfillMergedUuids();

        Schema::table('merged_persons', static function (Blueprint $table) {
            $table->uuid('merged_uuid')->nullable(false)
                ->comment('eHealth identifier of the person or preperson merged into the identified patient')->change();
            $table->foreignId('merged_preperson_id')->nullable()
                ->comment('Preperson merged into the patient, when their record is known locally')->change();
        });
    }

    /**
     * Reverse the migrations. Rows whose merged person is not a locally known preperson cannot be represented
     * by the previous shape of the table, so they are dropped.
     *
     * @return void
     */
    public function down(): void
    {
        if (!Schema::hasTable('merged_persons')) {
            return;
        }

        DB::table('merged_persons')->whereNull('merged_preperson_id')->delete();

        Schema::table('merged_persons', static function (Blueprint $table) {
            if (Schema::hasColumn('merged_persons', 'merged_person_id')) {
                $table->dropConstrainedForeignId('merged_person_id');
            }

            if (Schema::hasColumn('merged_persons', 'merged_uuid')) {
                $table->dropColumn('merged_uuid');
            }
        });

        Schema::table('merged_persons', static function (Blueprint $table) {
            $table->foreignId('merged_preperson_id')->nullable(false)->change();
        });

        if (Schema::hasColumn('merged_persons', 'merged_preperson_id')) {
            Schema::table('merged_persons', static function (Blueprint $table) {
                $table->renameColumn('merged_preperson_id', 'merge_person_id');
            });
        }
    }

    /**
     * Fill the eHealth identifier of the merged person from the preperson the row already points at.
     *
     * @return void
     */
    private function backfillMergedUuids(): void
    {
        DB::table('merged_persons')
            ->whereNull('merged_uuid')
            ->whereNotNull('merged_preperson_id')
            ->orderBy('id')
            ->chunkById(500, static function (Collection $mergedPersons): void {
                $prepersonUuids = DB::table('prepersons')
                    ->whereIn('id', $mergedPersons->pluck('merged_preperson_id'))
                    ->pluck('uuid', 'id');

                foreach ($mergedPersons as $mergedPerson) {
                    DB::table('merged_persons')
                        ->where('id', $mergedPerson->id)
                        ->update(['merged_uuid' => $prepersonUuids[$mergedPerson->merged_preperson_id]]);
                }
            });
    }
};
