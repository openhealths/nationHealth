<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Install counterpart of update/0_1/2026_06_01_000002_create_referral_requests_tables.php.
 *
 * A fresh installation gets the current shape of these tables directly, including the author
 * fields that a later update migration adds to service_request_requests.
 */
return new class extends Migration
{
    public function up(): void
    {
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
                $table->foreignId('intent_id')->nullable()->constrained('codings');
                $table->foreignId('category_id')->nullable()->constrained('codeable_concepts');
                $table->foreignId('based_on_id')->nullable()->constrained('identifiers');
                $table->foreignId('context_id')->nullable()->constrained('identifiers');
                $table->foreignId('priority_id')->nullable()->constrained('codeable_concepts');
                $table->text('note')->nullable();
                $table->text('patient_instruction')->nullable();
                $table->json('reason_reference')->nullable();
                $table->string('inform_with')->nullable();
                $table->text('supporting_info')->nullable(); // JSON list of conditions / diagnostic reports
                $table->timestamps();
            });
        }

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
                $table->foreignId('intent_id')->nullable()->constrained('codings');
                $table->foreignId('category_id')->nullable()->constrained('codeable_concepts');
                $table->foreignId('based_on_id')->nullable()->constrained('identifiers');
                $table->foreignId('context_id')->nullable()->constrained('identifiers');
                $table->foreignId('priority_id')->nullable()->constrained('codeable_concepts');
                $table->text('note')->nullable();
                $table->text('supporting_info')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('device_request_requests');
        Schema::dropIfExists('service_request_requests');
    }
};
