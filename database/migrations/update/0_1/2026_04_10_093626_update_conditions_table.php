<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('conditions', static function (Blueprint $table) {
            if (!Schema::hasColumn('conditions', 'person_id')) {
                $table->foreignId('person_id')->after('uuid')->constrained('persons');
            }
            if (!Schema::hasColumn('conditions', 'explanatory_letter')) {
                $table->string('explanatory_letter')->nullable()->after('asserted_date');
            }
            if (!Schema::hasColumn('conditions', 'ehealth_inserted_at')) {
                $table->timestamp('ehealth_inserted_at')->nullable()->after('explanatory_letter');
            }
            if (!Schema::hasColumn('conditions', 'ehealth_updated_at')) {
                $table->timestamp('ehealth_updated_at')->nullable()->after('ehealth_inserted_at');
            }
            if (!Schema::hasColumn('conditions', 'stage_summary_id')) {
                $table->foreignId('stage_summary_id')->nullable()->after('ehealth_updated_at')->constrained('codeable_concepts');
            }
            if (Schema::hasColumn('conditions', 'encounter_id')) {
                $table->dropForeign(['encounter_id']);
                $table->dropColumn('encounter_id');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('conditions', static function (Blueprint $table) {
            if (Schema::hasColumn('conditions', 'person_id')) {
                $table->dropForeign(['person_id']);
                $table->dropColumn('person_id');
            }
            if (Schema::hasColumn('conditions', 'explanatory_letter')) {
                $table->dropColumn('explanatory_letter');
            }
            if (Schema::hasColumn('conditions', 'ehealth_inserted_at')) {
                $table->dropColumn('ehealth_inserted_at');
            }
            if (Schema::hasColumn('conditions', 'ehealth_updated_at')) {
                $table->dropColumn('ehealth_updated_at');
            }
            if (Schema::hasColumn('conditions', 'stage_summary_id')) {
                $table->dropForeign(['stage_summary_id']);
                $table->dropColumn('stage_summary_id');
            }
            if (!Schema::hasColumn('conditions', 'encounter_id')) {
                $table->foreignId('encounter_id')->after('person_id')->constrained('encounters');
            }
        });
    }
};
