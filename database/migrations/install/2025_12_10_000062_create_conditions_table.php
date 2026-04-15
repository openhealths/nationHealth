<?php

declare(strict_types=1);

use App\Enums\Person\ConditionClinicalStatus;
use App\Enums\Person\ConditionVerificationStatus;
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
        Schema::create('conditions', static function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('person_id')->constrained('persons');
            $table->boolean('primary_source');
            $table->foreignId('asserter_id')->nullable()->constrained('identifiers')->cascadeOnDelete();
            $table->foreignId('report_origin_id')->nullable()->constrained('codeable_concepts')->cascadeOnDelete();
            $table->foreignId('context_id')->constrained('identifiers')->cascadeOnDelete();
            $table->foreignId('code_id')->constrained('codeable_concepts')->cascadeOnDelete();
            $table->enum('clinical_status', ConditionClinicalStatus::values());
            $table->enum('verification_status', ConditionVerificationStatus::values());
            $table->foreignId('severity_id')->nullable()->constrained('codeable_concepts')->cascadeOnDelete();
            $table->timestamp('onset_date');
            $table->timestamp('asserted_date')->nullable();
            $table->string('explanatory_letter')->nullable();
            $table->timestamp('ehealth_inserted_at')->nullable();
            $table->timestamp('ehealth_updated_at')->nullable();
            $table->foreignId('stage_summary_id')->nullable()->constrained('codeable_concepts')->cascadeOnDelete();
            $table->timestamps();
        });

        Schema::create('condition_body_sites', static function (Blueprint $table) {
            $table->id();
            $table->foreignId('condition_id')->constrained('conditions')->cascadeOnDelete();
            $table->foreignId('codeable_concept_id')->constrained('codeable_concepts')->cascadeOnDelete();
            $table->timestamps();
        });

        Schema::create('condition_evidences', static function (Blueprint $table) {
            $table->id();
            $table->foreignId('condition_id')->constrained('conditions')->cascadeOnDelete();
            $table->foreignId('codes_id')->nullable()->constrained('codeable_concepts')->cascadeOnDelete();
            $table->foreignId('details_id')->nullable()->constrained('identifiers')->cascadeOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('condition_evidences');

        Schema::dropIfExists('condition_body_sites');

        Schema::dropIfExists('conditions');
    }
};
