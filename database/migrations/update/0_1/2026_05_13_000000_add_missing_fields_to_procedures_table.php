<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('procedures', static function (Blueprint $table) {
            if (!Schema::hasColumn('procedures', 'status_reason_id')) {
                $table->foreignId('status_reason_id')->nullable()->after('status')->constrained('codeable_concepts');
            }

            if (!Schema::hasColumn('procedures', 'origin_episode_id')) {
                $table->foreignId('origin_episode_id')->nullable()->after('encounter_id')->constrained('identifiers');
            }

            if (!Schema::hasColumn('procedures', 'explanatory_letter')) {
                $table->text('explanatory_letter')->nullable()->after('note');
            }
        });

        if (!Schema::hasTable('procedure_used_references')) {
            Schema::create('procedure_used_references', static function (Blueprint $table) {
                $table->id();
                $table->foreignId('procedure_id')->constrained()->cascadeOnDelete();
                $table->foreignId('identifier_id')->constrained('identifiers')->cascadeOnDelete();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('procedure_used_references');

        Schema::table('procedures', static function (Blueprint $table) {
            if (Schema::hasColumn('procedures', 'status_reason_id')) {
                $table->dropForeign(['status_reason_id']);
                $table->dropColumn('status_reason_id');
            }

            if (Schema::hasColumn('procedures', 'origin_episode_id')) {
                $table->dropForeign(['origin_episode_id']);
                $table->dropColumn('origin_episode_id');
            }

            if (Schema::hasColumn('procedures', 'explanatory_letter')) {
                $table->dropColumn('explanatory_letter');
            }
        });
    }
};
