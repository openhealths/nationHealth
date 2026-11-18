<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('care_plans', 'managing_organization_id')) {
            Schema::table('care_plans', static function (Blueprint $table): void {
                $table->foreignId('managing_organization_id')->nullable()->constrained('identifiers');
            });
        }
        Schema::table('care_plans', static function (Blueprint $table): void {
            $table->foreignId('author_id')->nullable()->change();
            $table->date('period_start')->nullable()->change();
            $table->foreignId('author_reference_id')->nullable()->constrained('identifiers');
            $table->foreignId('terms_of_service_concept_id')->nullable()->constrained('codeable_concepts');
            $table->string('intent')->nullable();
            $table->foreignId('subject_reference_id')->nullable()->constrained('identifiers');
            $table->foreignId('status_reason_id')->nullable()->constrained('codeable_concepts');
            $table->foreignId('based_on_id')->nullable()->constrained('identifiers');
            $table->foreignId('part_of_id')->nullable()->constrained('identifiers');

            $table->timestamp('details_synced_at')->nullable();
            $table->unique(['legal_entity_id', 'person_id', 'uuid'], 'care_plans_entity_person_uuid_unique');
        });
        Schema::table('care_plan_activities', static function (Blueprint $table): void {
            $table->foreignId('author_id')->nullable()->change();
            $table->foreignId('care_plan_reference_id')->nullable()->constrained('identifiers');
            foreach (['location_identifier_id', 'performer_identifier_id', 'program_identifier_id'] as $column) {
                $table->foreignId($column)->nullable()->constrained('identifiers');
            }
            $table->foreignId('status_reason_concept_id')->nullable()->constrained('codeable_concepts');
            $table->foreignId('remaining_quantity_id')->nullable()->constrained('quantities');
            $table->string('remaining_quantity_type')->nullable();
            $table->json('scheduled_timing')->nullable();
            $table->text('scheduled_string')->nullable();

            $table->timestamp('details_synced_at')->nullable();
            $table->unique(['care_plan_id', 'uuid'], 'care_plan_activities_plan_uuid_unique');
        });
        foreach (['care_plan_contributors' => ['care_plan_id', 'care_plans', 'identifier_id', 'identifiers'],
            'care_plan_activity_authors' => ['activity_id', 'care_plan_activities', 'identifier_id', 'identifiers'],
            'care_plan_activity_reason_codes' => ['activity_id', 'care_plan_activities', 'concept_id', 'codeable_concepts'],
            'care_plan_activity_goal_concepts' => ['activity_id', 'care_plan_activities', 'concept_id', 'codeable_concepts'],
            'care_plan_activity_outcome_concepts' => ['activity_id', 'care_plan_activities', 'concept_id', 'codeable_concepts']] as $name => [$ownerColumn, $ownerTable, $valueColumn, $valueTable]) {
            Schema::create($name, static function (Blueprint $table) use ($name, $ownerColumn, $ownerTable, $valueColumn, $valueTable): void {
                $table->id();
                $table->foreignId($ownerColumn)->constrained($ownerTable)->cascadeOnDelete();
                $table->foreignId($valueColumn)->constrained($valueTable);
                $table->unsignedInteger('position');
                $table->unique([$ownerColumn, $valueColumn], $name.'_owner_value_unique');
            });
        }
    }

    /** Recovery uses the pre-cutover backup; narrowing shared primitive columns would lose data. */
    public function down(): void
    {
        throw new RuntimeException('Care Plan FHIR storage requires a forward fix or restoration of the pre-cutover backup.');
    }
};
