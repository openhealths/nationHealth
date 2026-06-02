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
        // 1. dosages table (for Dose model)
        if (!Schema::hasTable('dosages')) {
            Schema::create('dosages', static function (Blueprint $table) {
                $table->id();
                $table->decimal('value', 15, 4)->nullable();
                $table->string('unit')->nullable();
                $table->string('system')->nullable();
                $table->string('code')->nullable();
                $table->timestamps();
            });
        }

        // 2. medication_request_requests table
        if (!Schema::hasTable('medication_request_requests')) {
            Schema::create('medication_request_requests', static function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->foreignId('employee_id')->constrained('employees');
                $table->foreignId('person_id')->constrained('persons');
                $table->foreignId('division_id')->nullable()->constrained('divisions');
                $table->string('status');
                $table->string('request_number')->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('ended_at')->nullable();
                $table->string('medication_id'); // INN-based or product code
                $table->decimal('medication_qty', 15, 2);
                $table->string('medication_program_id')->nullable();
                $table->string('intent');
                $table->string('category')->nullable();
                $table->foreignId('based_on_id')->nullable()->constrained('care_plan_activities');
                $table->foreignId('context_id')->nullable()->constrained('encounters');
                $table->string('priority')->nullable();
                $table->foreignId('prior_prescription_id')->nullable()->constrained('medication_request_requests');
                $table->string('container_dosage')->nullable();
                $table->text('note')->nullable();
                $table->timestamps();
            });
        }

        // 3. dosage_instructions table
        if (!Schema::hasTable('dosage_instructions')) {
            Schema::create('dosage_instructions', static function (Blueprint $table) {
                $table->id();
                $table->foreignId('medication_request_request_id')->constrained('medication_request_requests')->cascadeOnDelete();
                $table->string('medication_request_id')->nullable(); // UUID of final MedicationRequest from eHealth
                $table->integer('sequence')->nullable();
                $table->text('text')->nullable();
                $table->foreignId('additional_instruction_id')->nullable()->constrained('codeable_concepts');
                $table->text('patient_instruction')->nullable();
                $table->text('timing')->nullable();
                $table->boolean('as_needed_boolean')->default(false);
                $table->foreignId('site_id')->nullable()->constrained('codeable_concepts');
                $table->string('route')->nullable();
                $table->string('method')->nullable();
                $table->text('dose_and_rate')->nullable();
                $table->string('max_dose_per_period')->nullable();
                $table->string('max_dose_per_administration')->nullable();
                $table->string('max_dose_per_lifetime')->nullable();
                $table->timestamps();
            });
        }

        // 4. dose_rates table
        if (!Schema::hasTable('dose_rates')) {
            Schema::create('dose_rates', static function (Blueprint $table) {
                $table->id();
                $table->foreignId('dosage_instruction_id')->nullable()->constrained('dosage_instructions')->cascadeOnDelete();
                $table->foreignId('type_id')->nullable()->constrained('codeable_concepts');
                $table->foreignId('range_id')->nullable()->constrained('ranges');
                $table->string('rate_ratio')->nullable();
                $table->timestamps();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('dose_rates');
        Schema::dropIfExists('dosage_instructions');
        Schema::dropIfExists('medication_request_requests');
        Schema::dropIfExists('dosages');
    }
};
