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
        Schema::table('care_plans', function (Blueprint $table) {
            $table->foreignId('category_id')->nullable()->after('status')->constrained('codeable_concepts');
            $table->foreignId('encounter_identifier_id')->nullable()->after('encounter_id')->constrained('identifiers');
            $table->foreignId('care_manager_id')->nullable()->after('encounter_identifier_id')->constrained('identifiers');
        });

        Schema::create('care_plan_supporting_info', function (Blueprint $table) {
            $table->id();
            $table->foreignId('care_plan_id')->constrained('care_plans')->onDelete('cascade');
            $table->foreignId('identifier_id')->constrained('identifiers')->onDelete('cascade');
            $table->timestamps();
        });

        Schema::table('care_plan_activities', function (Blueprint $table) {
            $table->foreignId('kind_id')->nullable()->after('kind')->constrained('codeable_concepts');
            $table->foreignId('product_codeable_concept_id')->nullable()->after('product_codeable_concept')->constrained('codeable_concepts');
            $table->foreignId('reason_code_id')->nullable()->after('reason_code')->constrained('codeable_concepts');
            $table->foreignId('outcome_codeable_concept_id')->nullable()->after('outcome_codeable_concept')->constrained('codeable_concepts');
            $table->foreignId('product_reference_id')->nullable()->after('product_reference')->constrained('identifiers');
        });

        Schema::create('care_plan_activity_reasons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('activity_id')->constrained('care_plan_activities')->onDelete('cascade');
            $table->foreignId('identifier_id')->constrained('identifiers')->onDelete('cascade');
            $table->timestamps();
        });

        Schema::create('care_plan_activity_goals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('activity_id')->constrained('care_plan_activities')->onDelete('cascade');
            $table->foreignId('identifier_id')->constrained('identifiers')->onDelete('cascade');
            $table->timestamps();
        });

        Schema::create('care_plan_activity_outcomes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('activity_id')->constrained('care_plan_activities')->onDelete('cascade');
            $table->foreignId('identifier_id')->constrained('identifiers')->onDelete('cascade');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('care_plan_activity_outcomes');
        Schema::dropIfExists('care_plan_activity_goals');
        Schema::dropIfExists('care_plan_activity_reasons');

        Schema::table('care_plan_activities', function (Blueprint $table) {
            $table->dropForeign(['kind_id']);
            $table->dropColumn('kind_id');
            $table->dropForeign(['product_codeable_concept_id']);
            $table->dropColumn('product_codeable_concept_id');
            $table->dropForeign(['reason_code_id']);
            $table->dropColumn('reason_code_id');
            $table->dropForeign(['outcome_codeable_concept_id']);
            $table->dropColumn('outcome_codeable_concept_id');
            $table->dropForeign(['product_reference_id']);
            $table->dropColumn('product_reference_id');
        });

        Schema::dropIfExists('care_plan_supporting_info');

        Schema::table('care_plans', function (Blueprint $table) {
            $table->dropForeign(['category_id']);
            $table->dropColumn('category_id');
            $table->dropForeign(['encounter_identifier_id']);
            $table->dropColumn('encounter_identifier_id');
            $table->dropForeign(['care_manager_id']);
            $table->dropColumn('care_manager_id');
        });
    }
};
