<?php

declare(strict_types=1);

use App\Enums\Person\EpisodeStatus;
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
        Schema::table('episodes', static function (Blueprint $table) {
            if (!Schema::hasColumn('episodes', 'closing_summary')) {
                $table->text('closing_summary')->nullable()->after('care_manager_id');
            }
            if (!Schema::hasColumn('episodes', 'explanatory_letter')) {
                $table->string('explanatory_letter')->nullable()->after('closing_summary');
            }
            if (!Schema::hasColumn('episodes', 'status_reason_id')) {
                $table->foreignId('status_reason_id')->nullable()->after('explanatory_letter')->constrained('codeable_concepts');
            }
        });

        if (!Schema::hasTable('episode_current_diagnoses')) {
            Schema::create('episode_current_diagnoses', static function (Blueprint $table) {
                $table->id();
                $table->foreignId('episode_id')->constrained('episodes')->cascadeOnDelete();
                $table->foreignId('code_id')->constrained('codeable_concepts');
                $table->foreignId('condition_id')->constrained('identifiers');
                $table->foreignId('role_id')->constrained('codeable_concepts');
                $table->integer('rank')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('episode_diagnoses_history')) {
            Schema::create('episode_diagnoses_history', static function (Blueprint $table) {
                $table->id();
                $table->foreignId('episode_id')->constrained('episodes')->cascadeOnDelete();
                $table->foreignId('evidence_id')->nullable()->constrained('identifiers');
                $table->timestamp('date')->nullable();
                $table->boolean('is_active')->default(false);
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('episode_diagnoses_history_items')) {
            Schema::create('episode_diagnoses_history_items', static function (Blueprint $table) {
                $table->id();
                $table->foreignId('episode_diagnoses_history_id')->constrained(
                    'episode_diagnoses_history'
                )->cascadeOnDelete();
                $table->foreignId('condition_id')->constrained('identifiers');
                $table->foreignId('code_id')->constrained('codeable_concepts');
                $table->foreignId('role_id')->constrained('codeable_concepts');
                $table->integer('rank')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('episode_status_history')) {
            Schema::create('episode_status_history', static function (Blueprint $table) {
                $table->id();
                $table->foreignId('episode_id')->constrained('episodes')->cascadeOnDelete();
                $table->enum('status', EpisodeStatus::values());
                $table->foreignId('status_reason_id')->nullable()->constrained('codeable_concepts');
                $table->uuid('ehealth_inserted_by');
                $table->timestamp('ehealth_inserted_at');
                $table->timestamps();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('episode_status_history');
        Schema::dropIfExists('episode_diagnoses_history_items');
        Schema::dropIfExists('episode_diagnoses_history');
        Schema::dropIfExists('episode_current_diagnoses');

        Schema::table('episodes', static function (Blueprint $table) {
            if (Schema::hasColumn('episodes', 'care_manager_id')) {
                $table->dropForeign(['care_manager_id']);
            }
            if (Schema::hasColumn('episodes', 'status_reason_id')) {
                $table->dropForeign(['status_reason_id']);
            }

            $columns = array_filter(
                ['closing_summary', 'explanatory_letter', 'status_reason_id'],
                static fn (string $column) => Schema::hasColumn('episodes', $column)
            );

            if (!empty($columns)) {
                $table->dropColumn($columns);
            }
        });
    }
};
