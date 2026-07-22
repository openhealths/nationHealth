<?php

declare(strict_types=1);

use App\Enums\HealthcareService\Status;
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
        Schema::create('healthcare_services', static function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique()->nullable();
            $table->foreignId('division_id')->constrained()->cascadeOnDelete()->cascadeOnUpdate();
            $table->foreignId('legal_entity_id')->constrained();
            $table->string('speciality_type')->nullable();
            $table->string('providing_condition')->nullable();
            $table->string('license_id')->nullable();
            $table->enum('status', Status::values());
            $table->foreignId('category_id')->constrained('codeable_concepts');
            $table->foreignId('type_id')->nullable()->constrained('codeable_concepts');
            $table->text('comment')->nullable();
            $table->jsonb('coverage_area')->nullable();
            $table->jsonb('available_time')->nullable();
            $table->jsonb('not_available')->nullable();
            $table->boolean('is_active')->default(false);
            $table->jsonb('licensed_healthcare_service')->nullable();
            $table->date('ehealth_inserted_at')->nullable();
            $table->string('ehealth_inserted_by')->nullable();
            $table->date('ehealth_updated_at')->nullable();
            $table->string('ehealth_updated_by')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('healthcare_services');
    }
};
