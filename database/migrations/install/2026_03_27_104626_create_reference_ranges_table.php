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
        Schema::create('reference_ranges', static function (Blueprint $table) {
            $table->id();
            $table->foreignId('observation_id')->nullable()->constrained('observations')->cascadeOnDelete();
            $table->foreignId('observation_component_id')->nullable()->constrained('observation_components')->cascadeOnDelete();
            $table->foreignId('low_id')->nullable()->constrained('quantities');
            $table->foreignId('high_id')->nullable()->constrained('quantities');
            $table->foreignId('type_id')->nullable()->constrained('codeable_concepts');
            $table->foreignId('applies_to_id')->nullable()->constrained('codeable_concepts');
            $table->foreignId('age_id')->nullable()->constrained('ranges');
            $table->text('text')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reference_ranges');
    }
};
