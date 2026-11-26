<?php

declare(strict_types=1);

use App\Enums\JobStatus;
use App\Enums\Declaration\Status;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('declarations', static function (Blueprint $table) {
            $table->id();
            $table->uuid();
            $table->string('declaration_number')->unique();
            $table->foreignId('declaration_request_id')->nullable()->constrained();
            $table->foreignId('division_id')->nullable()->constrained();
            $table->foreignId('employee_id')->constrained();
            $table->foreignId('legal_entity_id')->constrained();
            $table->foreignId('person_id')->nullable()->constrained('persons');
            $table->date('end_date');
            $table->dateTime('inserted_at');
            $table->boolean('is_active')->default(false);
            $table->string('reason')->nullable();
            $table->string('reason_description')->nullable();
            $table->dateTime('signed_at')->nullable();
            $table->date('start_date');
            $table->enum('status', Status::values());
            $table->enum('sync_status', JobStatus::values())->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('declarations');
    }
};
