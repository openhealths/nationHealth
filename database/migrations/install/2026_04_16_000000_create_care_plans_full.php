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
        if (!Schema::hasTable('care_plans')) {
            Schema::create('care_plans', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->nullable()->index();
                $table->foreignId('person_id')->constrained('persons');
                $table->foreignId('author_id')->constrained('employees');
                $table->foreignId('legal_entity_id')->constrained('legal_entities');
                $table->string('status'); 
                $table->string('category')->nullable();
                $table->string('clinical_protocol')->nullable();
                $table->string('context')->nullable();
                $table->string('title');
                $table->date('period_start');
                $table->date('period_end')->nullable();
                $table->string('terms_of_service')->nullable();
                $table->foreignId('encounter_id')->nullable()->constrained('encounters');
                $table->json('addresses')->nullable();
                $table->text('description')->nullable();
                $table->json('supporting_info')->nullable();
                $table->text('note')->nullable();
                $table->string('inform_with')->nullable();
                $table->string('requisition')->nullable()->index();
                
                // FHIR conversion fields
                $table->foreignId('category_id')->nullable()->constrained('codeable_concepts');
                $table->foreignId('encounter_identifier_id')->nullable()->constrained('identifiers');
                $table->foreignId('care_manager_id')->nullable()->constrained('identifiers');
                
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('care_plan_activities')) {
            Schema::create('care_plan_activities', function (Blueprint $table) {
                $table->id();
                $table->foreignId('care_plan_id')->constrained('care_plans')->onDelete('cascade');
                $table->foreignId('author_id')->constrained('employees');
                $table->string('status');
                $table->string('kind');
                $table->string('product_reference')->nullable();
                $table->string('product_codeable_concept')->nullable();
                $table->decimal('quantity', 15, 2)->nullable();
                $table->string('quantity_system')->nullable();
                $table->string('quantity_code')->nullable();
                $table->decimal('daily_amount', 15, 2)->nullable();
                $table->string('daily_amount_system')->nullable();
                $table->string('daily_amount_code')->nullable();
                $table->string('reason_code')->nullable();
                $table->string('reason_reference')->nullable();
                $table->string('goal')->nullable();
                $table->text('description')->nullable();
                $table->string('program')->nullable();
                $table->date('scheduled_period_start')->nullable();
                $table->date('scheduled_period_end')->nullable();
                $table->text('status_reason')->nullable();
                $table->string('outcome_reference')->nullable();
                $table->string('outcome_codeable_concept')->nullable();
                
                // FHIR conversion fields
                $table->foreignId('kind_id')->nullable()->constrained('codeable_concepts');
                $table->foreignId('product_codeable_concept_id')->nullable()->constrained('codeable_concepts');
                $table->foreignId('reason_code_id')->nullable()->constrained('codeable_concepts');
                $table->foreignId('outcome_codeable_concept_id')->nullable()->constrained('codeable_concepts');
                $table->foreignId('product_reference_id')->nullable()->constrained('identifiers');
                
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('care_plan_supporting_info')) {
            Schema::create('care_plan_supporting_info', function (Blueprint $table) {
                $table->id();
                $table->foreignId('care_plan_id')->constrained('care_plans')->onDelete('cascade');
                $table->foreignId('identifier_id')->constrained('identifiers')->onDelete('cascade');
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('care_plan_activity_reasons')) {
            Schema::create('care_plan_activity_reasons', function (Blueprint $table) {
                $table->id();
                $table->foreignId('activity_id')->constrained('care_plan_activities')->onDelete('cascade');
                $table->foreignId('identifier_id')->constrained('identifiers')->onDelete('cascade');
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('care_plan_activity_goals')) {
            Schema::create('care_plan_activity_goals', function (Blueprint $table) {
                $table->id();
                $table->foreignId('activity_id')->constrained('care_plan_activities')->onDelete('cascade');
                $table->foreignId('identifier_id')->constrained('identifiers')->onDelete('cascade');
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('care_plan_activity_outcomes')) {
            Schema::create('care_plan_activity_outcomes', function (Blueprint $table) {
                $table->id();
                $table->foreignId('activity_id')->constrained('care_plan_activities')->onDelete('cascade');
                $table->foreignId('identifier_id')->constrained('identifiers')->onDelete('cascade');
                $table->timestamps();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('care_plan_activity_outcomes');
        Schema::dropIfExists('care_plan_activity_goals');
        Schema::dropIfExists('care_plan_activity_reasons');
        Schema::dropIfExists('care_plan_activity_reasons');
        Schema::dropIfExists('care_plan_activities');
        Schema::dropIfExists('care_plan_supporting_info');
        Schema::dropIfExists('care_plans');
    }
};
