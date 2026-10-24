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
        foreach (['display', 'version', 'user_selected'] as $column) {
            if (!Schema::hasColumn('codings', $column)) {
                Schema::table('codings', static function (Blueprint $table) use ($column): void {
                    $column === 'user_selected' ? $table->boolean($column)->nullable() : $table->string($column)->nullable();
                });
            }
        }
        Schema::table('medication_request_requests', static function (Blueprint $table): void {
            $table->decimal('medication_qty', 24, 10)->nullable()->change();
            $table->unsignedSmallInteger('fhir_version')->nullable();
            $table->json('fhir_metadata')->nullable();
        });
        Schema::table('dosage_instructions', static function (Blueprint $table): void {
            $table->unsignedInteger('position')->nullable();
            $table->json('fhir_data')->nullable();
        });
        Schema::create('medication_request_fhir_references', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('medication_request_id')->constrained('medication_request_requests')->cascadeOnDelete();
            $table->foreignId('identifier_id')->constrained('identifiers');
            $table->string('field');
            $table->unsignedInteger('position');
            $table->unique(['medication_request_id', 'field', 'position'], 'medication_request_reference_position_unique');
            $table->unique('identifier_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new RuntimeException('Medication FHIR storage requires a forward fix or restoration of the pre-cutover backup.');
    }
};
