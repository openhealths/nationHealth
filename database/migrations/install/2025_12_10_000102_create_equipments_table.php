<?php

declare(strict_types=1);

use App\Enums\Equipment\{AvailabilityStatus, Status, Type};
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
        Schema::create('equipments', static function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique()->nullable();
            $table->foreignId('legal_entity_id')->nullable()->constrained();
            $table->foreignId('division_id')->nullable()->constrained();
            $table->foreignId('parent_id')->nullable()->constrained('equipments')->nullOnDelete();
            $table->foreignId('recorder')->nullable()->constrained('employees');
            $table->uuid('device_definition_id')->comment('Reference to device definition')->nullable(); // separate table
            $table->string('type');
            $table->string('serial_number')->nullable();
            $table->enum('status', Status::values());
            $table->enum('availability_status', AvailabilityStatus::values());
            $table->string('manufacturer')->nullable();
            $table->date('manufacture_date')->nullable();
            $table->string('model_number')->nullable();
            $table->string('inventory_number')->nullable();
            $table->string('lot_number')->nullable();
            $table->date('expiration_date')->nullable();
            $table->text('note')->nullable();
            $table->jsonb('properties')->nullable();
            $table->enum('error_reason', ['typo'])->nullable(); // https://e-health-ua.atlassian.net/wiki/spaces/ESOZ/pages/18769543191/equipment_status_reasons
            $table->dateTime('ehealth_inserted_at')->nullable();
            $table->uuid('ehealth_inserted_by')->nullable();
            $table->dateTime('ehealth_updated_at')->nullable();
            $table->uuid('ehealth_updated_by')->nullable();
            $table->timestamps();
        });

        Schema::create('equipment_names', static function (Blueprint $table) {
            $table->id();
            $table->foreignId('equipment_id')->constrained('equipments');
            $table->string('name');
            $table->enum('type', Type::values());
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('equipment_names');
        Schema::dropIfExists('equipments');
    }
};
