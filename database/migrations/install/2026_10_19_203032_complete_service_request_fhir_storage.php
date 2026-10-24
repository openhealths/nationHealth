<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['display', 'version', 'user_selected'] as $column) {
            if (!Schema::hasColumn('codings', $column)) {
                Schema::table('codings', static function (Blueprint $table) use ($column): void {
                    $column === 'user_selected' ? $table->boolean($column)->nullable() : $table->string($column)->nullable();
                });
            }
        }
        Schema::table('quantities', static fn (Blueprint $table) => $table->decimal('value', 24, 10)->change());
        Schema::table('periods', static function (Blueprint $table): void {
            $table->timestamp('start', 6)->change();
            $table->timestamp('end', 6)->nullable()->change();
        });
        Schema::table('service_request_requests', static function (Blueprint $table): void {
            $table->foreignId('employee_id')->nullable()->change();
            $table->decimal('quantity', 24, 10)->nullable()->change();
            $table->timestamp('started_at', 6)->nullable()->change();
            $table->timestamp('ended_at', 6)->nullable()->change();
            foreach (['code_identifier_id', 'program_identifier_id', 'requester_employee_id', 'requester_legal_entity_id', 'subject_identifier_id'] as $column) {
                $table->foreignId($column)->nullable()->constrained('identifiers');
            }
            foreach (['quantity_id', 'remaining_quantity_id'] as $column) {
                $table->foreignId($column)->nullable()->constrained('quantities');
            }
            $table->timestamp('occurrence_date_time', 6)->nullable();
            $table->string('clinical_status')->nullable();
            $table->json('fhir_metadata')->nullable();
            $table->unsignedSmallInteger('fhir_version')->nullable();
        });
        Schema::create('service_request_fhir_references', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('service_request_id')->constrained('service_request_requests')->cascadeOnDelete();
            $table->foreignId('identifier_id')->constrained('identifiers');
            $table->string('collection');
            $table->unsignedInteger('position');
            $table->unique(['service_request_id', 'collection', 'position'], 'service_request_fhir_reference_position_unique');
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Service Request FHIR recovery requires a forward fix or restoration of the matching pre-cutover backup.');
    }
};
