<?php

declare(strict_types=1);

use App\Enums\Employee\RequestStatus;
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
        Schema::create('employee_requests', function (Blueprint $table) {
            $table->id();
            $table->uuid()->nullable();
            $table->uuid('division_uuid')->nullable();
            $table->uuid('legal_entity_uuid')->nullable();
            $table->string('position')->nullable();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->string('employee_type')->nullable();
            $table->string('email')->nullable();
            $table->dateTime('inserted_at')->nullable();
            $table->enum('status', array_column(RequestStatus::cases(), 'value'))->default(RequestStatus::NEW->value)->nullable();
            $table->foreignId('employee_id')->nullable()->constrained('employees')->onDelete('cascade');
            $table->foreignId('legal_entity_id')->nullable()->constrained('legal_entities')->onDelete('cascade');
            $table->foreignId('division_id')->nullable()->constrained('divisions')->onDelete('cascade');
            $table->foreignId('user_id')->nullable()->constrained('users')->onDelete('cascade');
            $table->foreignId('party_id')->nullable()->constrained('parties')->onDelete('cascade');
            $table->timestamp('applied_at')->nullable();
            $table->enum('sync_status', JobStatus::values())->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('employee_requests');
    }
};
