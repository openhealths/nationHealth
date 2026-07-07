<?php

declare(strict_types=1);

use App\Enums\JobStatus;
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
        Schema::create('legal_entities', function (Blueprint $table) {
            $table->id();
            $table->uuid();
            $table->json('accreditation')->nullable();
            $table->json('archive')->nullable();
            $table->string('beneficiary')->nullable();
            $table->json('edr')->nullable();
            $table->boolean('edr_verified')->nullable();
            $table->string('edrpou')->nullable();
            $table->string('email')->nullable();
            $table->uuid('inserted_by')->nullable();
            $table->boolean('is_active')->default(true);
            $table->text('nhs_comment')->nullable();
            $table->boolean('nhs_reviewed')->default(false);
            $table->boolean('nhs_verified')->default(false);
            $table->string('receiver_funds_code')->nullable();
            $table->string('status')->nullable();
            $table->foreignId('legal_entity_type_id')->constrained('legal_entity_types')->cascadeOnDelete();
            $table->uuid('updated_by')->nullable();
            $table->string('client_id')->nullable();
            $table->string('client_secret')->nullable();
            $table->string('website')->nullable();

            $table->enum('sync_status', JobStatus::values())->nullable();
            $table->enum('division_sync_status', JobStatus::values())->nullable();
            $table->enum('hcs_sync_status', JobStatus::values())->nullable();
            $table->enum('employee_sync_status', JobStatus::values())->nullable();
            $table->enum('employee_role_sync_status', JobStatus::values())->nullable();
            $table->enum('employee_request_sync_status', JobStatus::values())->nullable();
            $table->enum('license_sync_status', JobStatus::values())->nullable();
            $table->enum('document_sync_status', JobStatus::values())->nullable();
            $table->enum('declaration_sync_status', JobStatus::values())->nullable();
            $table->enum('declaration_request_sync_status', JobStatus::values())->nullable();
            $table->enum('equipment_sync_status', JobStatus::values())->nullable();
            $table->enum('contract_sync_status', JobStatus::values())->nullable();
            $table->enum('contract_request_sync_status', JobStatus::values())->nullable();
            $table->enum('episode_sync_status', JobStatus::values())->nullable();
            $table->enum('encounter_sync_status', JobStatus::values())->nullable();
            $table->enum('clinical_impression_sync_status', JobStatus::values())->nullable();
            $table->enum('immunization_sync_status', JobStatus::values())->nullable();
            $table->enum('observation_sync_status', JobStatus::values())->nullable();
            $table->enum('condition_sync_status', JobStatus::values())->nullable();
            $table->enum('diagnostic_report_sync_status', JobStatus::values())->nullable();
            $table->enum('procedure_sync_status', JobStatus::values())->nullable();

            $table->timestamp('inserted_at')->nullable();
            $table->date('ehealth_inserted_at')->nullable();
            $table->uuid('ehealth_inserted_by')->nullable();
            $table->date('ehealth_updated_at')->nullable();
            $table->uuid('ehealth_updated_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('legal_entities');
    }
};
