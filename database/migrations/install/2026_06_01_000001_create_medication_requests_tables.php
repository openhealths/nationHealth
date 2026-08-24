<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Install counterpart of update/0_1/2026_06_01_000001_create_medication_requests_tables.php.
 *
 * A fresh installation gets the current shape of these tables directly, including the columns
 * that later update migrations add to existing databases: inform_with and ehealth_payload.
 */
return new class extends Migration
{
    public function up(): void
    {
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
                $table->foreignId('intent_id')->nullable()->constrained('codings');
                $table->foreignId('category_id')->nullable()->constrained('codeable_concepts');
                $table->foreignId('based_on_id')->nullable()->constrained('identifiers');
                $table->foreignId('context_id')->nullable()->constrained('identifiers');
                $table->foreignId('priority_id')->nullable()->constrained('codeable_concepts');
                $table->foreignId('prior_prescription_id')->nullable()->constrained('medication_request_requests');
                $table->string('container_dosage')->nullable();
                $table->text('note')->nullable();
                $table->string('inform_with')->nullable();
                $table->json('ehealth_payload')->nullable();
                $table->string('source')->default('local'); // 'local' = drafted here, 'ehealth' = synced from ЄСОЗ
                $table->timestamps();
            });
        }

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

    public function down(): void
    {
        Schema::dropIfExists('dose_rates');
        Schema::dropIfExists('dosage_instructions');
        Schema::dropIfExists('medication_request_requests');
        Schema::dropIfExists('dosages');
    }
};
