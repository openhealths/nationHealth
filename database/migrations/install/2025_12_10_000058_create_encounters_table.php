<?php

declare(strict_types=1);

use App\Enums\Person\EncounterStatus;
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
        Schema::create('encounters', static function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('person_id')->constrained('persons');
            $table->enum('status', EncounterStatus::values());
            $table->string('cancellation_reason')->nullable();
            $table->string('explanatory_letter')->nullable();
            $table->text('prescriptions')->nullable();
            $table->foreignId('visit_id')->nullable()->constrained('identifiers');
            $table->foreignId('episode_id')->constrained('identifiers');
            $table->foreignId('class_id')->constrained('codings');
            $table->foreignId('type_id')->constrained('codeable_concepts');
            $table->foreignId('priority_id')->nullable()->constrained('codeable_concepts');
            $table->foreignId('performer_id')->nullable()->constrained('identifiers');
            $table->foreignId('performer_speciality_id')->nullable()->constrained('codeable_concepts');
            $table->foreignId('division_id')->nullable()->constrained('identifiers');
            $table->foreignId('incoming_referral_id')->nullable()->constrained('identifiers');
            $table->foreignId('origin_episode_id')->nullable()->constrained('identifiers');
            $table->timestamp('ehealth_inserted_at')->nullable();
            $table->timestamp('ehealth_updated_at')->nullable();
            $table->timestamps();
        });

        Schema::create('encounter_reasons', static function (Blueprint $table) {
            $table->id();
            $table->foreignId('encounter_id')->constrained('encounters')->cascadeOnDelete();
            $table->foreignId('codeable_concept_id')->constrained('codeable_concepts')->cascadeOnDelete();
            $table->timestamps();
        });

        Schema::create('encounter_diagnoses', static function (Blueprint $table) {
            $table->id();
            $table->foreignId('encounter_id')->constrained('encounters')->cascadeOnDelete();
            $table->foreignId('condition_id')->constrained('identifiers')->cascadeOnDelete();
            $table->foreignId('role_id')->constrained('codeable_concepts')->cascadeOnDelete();
            $table->integer('rank')->nullable();
            $table->timestamps();
        });

        Schema::create('encounter_actions', static function (Blueprint $table) {
            $table->id();
            $table->foreignId('encounter_id')->constrained('encounters')->cascadeOnDelete();
            $table->foreignId('codeable_concept_id')->constrained('codeable_concepts')->cascadeOnDelete();
            $table->timestamps();
        });

        Schema::create('encounter_action_references', static function (Blueprint $table) {
            $table->id();
            $table->foreignId('encounter_id')->constrained('encounters')->cascadeOnDelete();
            $table->foreignId('identifier_id')->constrained('identifiers')->cascadeOnDelete();
            $table->timestamps();
        });

        Schema::create('encounter_participants', static function (Blueprint $table) {
            $table->id();
            $table->foreignId('encounter_id')->constrained('encounters')->cascadeOnDelete();
            $table->foreignId('identifier_id')->constrained('identifiers')->cascadeOnDelete();
            $table->timestamps();
        });

        Schema::create('encounter_supporting_info', static function (Blueprint $table) {
            $table->id();
            $table->foreignId('encounter_id')->constrained('encounters')->cascadeOnDelete();
            $table->foreignId('identifier_id')->constrained('identifiers')->cascadeOnDelete();
            $table->timestamps();
        });

        Schema::create('encounter_hospitalizations', static function (Blueprint $table) {
            $table->id();
            $table->foreignId('encounter_id')->constrained('encounters')->cascadeOnDelete();
            $table->string('pre_admission_identifier')->nullable();
            $table->foreignId('admit_source_id')->nullable()->constrained('codings');
            $table->foreignId('re_admission_id')->nullable()->constrained('codings');
            $table->foreignId('destination_id')->nullable()->constrained('identifiers');
            $table->foreignId('discharge_disposition_id')->nullable()->constrained('codings');
            $table->foreignId('discharge_department_id')->nullable()->constrained('codings');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('encounter_hospitalizations');
        Schema::dropIfExists('encounter_supporting_info');
        Schema::dropIfExists('encounter_participants');
        Schema::dropIfExists('encounter_action_references');
        Schema::dropIfExists('encounter_actions');
        Schema::dropIfExists('encounter_diagnoses');
        Schema::dropIfExists('encounter_reasons');
        Schema::dropIfExists('encounters');
    }
};
