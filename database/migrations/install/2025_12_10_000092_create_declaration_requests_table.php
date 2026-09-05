<?php

declare(strict_types=1);

use App\Enums\JobStatus;
use App\Enums\Declaration\RequestStatus;
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
        Schema::create('declaration_requests', static function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique()->nullable();
            $table->string('authorize_with')->nullable()->comment("identifier of person's auth method");
            $table->jsonb('data_to_be_signed')->nullable();
            $table->jsonb('documents')
                ->nullable()
                ->comment('Documents to upload before the approval, each with its own upload URL');
            $table->string('channel')->nullable();
            $table->integer('current_declaration_count')->nullable();
            $table->string('parent_declaration_uuid')
                ->nullable()
                ->comment('identifier of parent declaration in reorganized legal entity');
            $table->string('declaration_number')->nullable();
            $table->foreignId('division_id')
                ->comment('Registered Medical Service Provider Division identifier.')
                ->constrained();
            $table->foreignId('employee_id')
                ->comment('Employee ID with type=DOCTOR selected from available Employees as a third contract party.')
                ->constrained();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->foreignId('legal_entity_id')->constrained();
            $table->foreignId('person_id')->constrained('persons');
            // https://e-health-ua.atlassian.net/wiki/spaces/ESOZ/pages/18426003727/declaration_request_statuses
            $table->enum(
                'status',
                array_map(static fn (RequestStatus $status) => $status->value, RequestStatus::cases())
            )
                ->nullable();
            // https://e-health-ua.atlassian.net/wiki/spaces/ESOZ/pages/18426758317/declaration_request_status_reason
            $table->enum('status_reason', [
                'auto_approve',
                'doctor_approval_needed',
                'doctor_approved_over_limit',
                'doctor_confirmed',
                'doctor_reject',
                'doctor_rejected_over_limit',
                'doctor_signed',
                'patient_reject',
                'request_cancelled',
                'request_overdue'
            ])->nullable();
            $table->enum('sync_status', JobStatus::values())->nullable();
            $table->integer('system_declaration_limit')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('declaration_requests');
    }
};
