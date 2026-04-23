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
        Schema::create('episodes', static function (Blueprint $table) {
            $table->id();
            $table->uuid();
            $table->foreignId('person_id')->constrained('persons');
            $table->foreignId('encounter_id')->nullable()->constrained('encounters');
            $table->foreignId('episode_type_id')->nullable()->constrained('codings');
            $table->enum('status', EpisodeStatus::values());
            $table->string('name');
            $table->foreignId('managing_organization_id')->nullable()->constrained('identifiers');
            $table->foreignId('care_manager_id')->nullable()->constrained('identifiers');
            $table->foreignId('status_reason_id')->nullable()->constrained('codeable_concepts');
            $table->text('closing_summary')->nullable();
            $table->string('explanatory_letter')->nullable();
            $table->timestamp('ehealth_inserted_at')->nullable();
            $table->timestamp('ehealth_updated_at')->nullable();
            $table->timestamps();
        });

        Schema::create('episode_current_diagnoses', static function (Blueprint $table) {
            $table->id();
            $table->foreignId('episode_id')->constrained('episodes')->cascadeOnDelete();
            $table->foreignId('code_id')->constrained('codeable_concepts');
            $table->foreignId('condition_id')->constrained('identifiers');
            $table->foreignId('role_id')->constrained('codeable_concepts');
            $table->integer('rank')->nullable();
            $table->timestamps();
        });

        Schema::create('episode_diagnoses_history', static function (Blueprint $table) {
            $table->id();
            $table->foreignId('episode_id')->constrained('episodes')->cascadeOnDelete();
            $table->foreignId('evidence_id')->nullable()->constrained('identifiers');
            $table->timestamp('date')->nullable();
            $table->boolean('is_active')->default(false);
            $table->timestamps();
        });

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

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('episode_status_history');
        Schema::dropIfExists('episode_diagnoses_history_items');
        Schema::dropIfExists('episode_diagnoses_history');
        Schema::dropIfExists('episode_current_diagnoses');
        Schema::dropIfExists('episodes');
    }
};
