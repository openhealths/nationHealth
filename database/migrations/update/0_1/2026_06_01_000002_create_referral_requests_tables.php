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
        // 1. service_request_requests table
        if (!Schema::hasTable('service_request_requests')) {
            Schema::create('service_request_requests', static function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->foreignId('employee_id')->constrained('employees');
                $table->foreignId('person_id')->constrained('persons');
                $table->foreignId('division_id')->nullable()->constrained('divisions');
                $table->string('status');
                $table->string('request_number')->nullable(); // Requisition code
                $table->timestamp('started_at')->nullable();
                $table->timestamp('ended_at')->nullable();
                $table->string('service_id'); // Service code or concept
                $table->decimal('quantity', 15, 2)->default(1);
                $table->string('program_id')->nullable();
                $table->string('intent');
                $table->string('category')->nullable();
                $table->foreignId('based_on_id')->nullable()->constrained('care_plan_activities');
                $table->foreignId('context_id')->nullable()->constrained('encounters');
                $table->string('priority')->nullable();
                $table->text('note')->nullable();
                $table->text('supporting_info')->nullable(); // JSON list of conditions / diagnostic reports
                $table->timestamps();
            });
        }

        // 2. device_request_requests table
        if (!Schema::hasTable('device_request_requests')) {
            Schema::create('device_request_requests', static function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->foreignId('employee_id')->constrained('employees');
                $table->foreignId('person_id')->constrained('persons');
                $table->foreignId('division_id')->nullable()->constrained('divisions');
                $table->string('status');
                $table->string('request_number')->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('ended_at')->nullable();
                $table->string('device_id'); // Device code or reference
                $table->decimal('quantity', 15, 2)->default(1);
                $table->string('program_id')->nullable();
                $table->string('intent');
                $table->string('category')->nullable();
                $table->foreignId('based_on_id')->nullable()->constrained('care_plan_activities');
                $table->foreignId('context_id')->nullable()->constrained('encounters');
                $table->string('priority')->nullable();
                $table->text('note')->nullable();
                $table->text('supporting_info')->nullable();
                $table->timestamps();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('device_request_requests');
        Schema::dropIfExists('service_request_requests');
    }
};
