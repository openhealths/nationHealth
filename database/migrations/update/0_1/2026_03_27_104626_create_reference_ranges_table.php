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
        if (!Schema::hasTable('reference_ranges')) {
            Schema::create('reference_ranges', function (Blueprint $table) {
                $table->id();
                $table->nullableMorphs('referenceable');
                $table->foreignId('low_id')->nullable()->constrained('quantities');
                $table->foreignId('high_id')->nullable()->constrained('quantities');
                $table->foreignId('type_id')->nullable()->constrained('codeable_concepts');
                $table->foreignId('applies_to_id')->nullable()->constrained('codeable_concepts');
                $table->foreignId('age_low_id')->nullable()->constrained('quantities');
                $table->foreignId('age_high_id')->nullable()->constrained('quantities');
                $table->text('text')->nullable();
                $table->timestamps();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reference_ranges');
    }
};
