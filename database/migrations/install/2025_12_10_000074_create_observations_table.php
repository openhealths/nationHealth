<?php

declare(strict_types=1);

use App\Enums\Person\ObservationStatus;
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
        Schema::create('observations', static function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('person_id')->constrained('persons');
            $table->enum('status', ObservationStatus::values());
            $table->foreignId('diagnostic_report_id')->nullable()->constrained('identifiers');
            $table->foreignId('code_id')->constrained('codeable_concepts');
            $table->timestamp('effective_date_time')->nullable();
            $table->timestamp('issued');
            $table->boolean('primary_source');
            $table->foreignId('performer_id')->nullable()->constrained('identifiers');
            $table->foreignId('report_origin_id')->nullable()->constrained('codeable_concepts');
            $table->foreignId('interpretation_id')->nullable()->constrained('codeable_concepts');
            $table->text('comment')->nullable();
            $table->foreignId('body_site_id')->nullable()->constrained('codeable_concepts');
            $table->foreignId('method_id')->nullable()->constrained('codeable_concepts');
            $table->foreignId('reaction_on_id')->nullable()->constrained('identifiers');
            $table->foreignId('context_id')->nullable()->constrained('identifiers');
            $table->foreignId('specimen_id')->nullable()->constrained('identifiers');
            $table->foreignId('device_id')->nullable()->constrained('identifiers');
            $table->foreignId('based_on_id')->nullable()->constrained('identifiers');
            $table->string('explanatory_letter')->nullable();
            $table->timestamp('ehealth_inserted_at')->nullable();
            $table->timestamp('ehealth_updated_at')->nullable();
            $table->timestamps();
        });

        Schema::create('observation_categories', static function (Blueprint $table) {
            $table->id();
            $table->foreignId('observation_id')->constrained('observations')->cascadeOnDelete();
            $table->foreignId('codeable_concept_id')->constrained('codeable_concepts')->cascadeOnDelete();
            $table->timestamps();
        });

        Schema::create('observation_components', static function (Blueprint $table) {
            $table->id();
            $table->foreignId('observation_id')->constrained('observations')->cascadeOnDelete();
            $table->foreignId('code_id')->constrained('codeable_concepts');
            $table->foreignId('interpretation_id')->nullable()->constrained('codeable_concepts')->cascadeOnDelete();
            $table->timestamps();
        });

        Schema::create('sampled_data', static function (Blueprint $table) {
            $table->id();
            $table->integer('origin')->nullable();
            $table->integer('period')->nullable();
            $table->integer('factor')->nullable();
            $table->integer('lower_limit')->nullable();
            $table->integer('upper_limit')->nullable();
            $table->integer('dimensions')->nullable();
            $table->string('data');
            $table->timestamps();
        });

        Schema::create('ratios', static function (Blueprint $table) {
            $table->id();
            $table->foreignId('numerator_id')->nullable()->constrained('quantities');
            $table->foreignId('denominator_id')->nullable()->constrained('quantities');
            $table->timestamps();
        });

        Schema::create('ranges', static function (Blueprint $table) {
            $table->id();
            $table->foreignId('low_id')->nullable()->constrained('quantities');
            $table->foreignId('high_id')->nullable()->constrained('quantities');
            $table->timestamps();
        });

        Schema::create('values', static function (Blueprint $table) {
            $table->id();
            $table->foreignId('observation_id')->nullable()->constrained('observations')->cascadeOnDelete();
            $table->foreignId('observation_component_id')->nullable()->constrained('observation_components')->cascadeOnDelete();
            $table->foreignId('value_codeable_concept_id')->nullable()->constrained('codeable_concepts');
            $table->foreignId('value_quantity_id')->nullable()->constrained('quantities');
            $table->foreignId('value_ratio_id')->nullable()->constrained('ratios');
            $table->foreignId('value_range_id')->nullable()->constrained('ranges');
            $table->foreignId('value_sampled_data_id')->nullable()->constrained('sampled_data')->cascadeOnDelete();
            $table->string('value_string')->nullable();
            $table->boolean('value_boolean')->nullable();
            $table->timestamp('value_date_time')->nullable();
            $table->time('value_time')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('values');
        Schema::dropIfExists('ranges');
        Schema::dropIfExists('ratios');
        Schema::dropIfExists('sampled_data');
        Schema::dropIfExists('observation_components');
        Schema::dropIfExists('observation_categories');
        Schema::dropIfExists('observations');
    }
};
