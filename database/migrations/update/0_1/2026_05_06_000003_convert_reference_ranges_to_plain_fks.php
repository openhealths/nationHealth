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
        Schema::table('reference_ranges', static function (Blueprint $table) {
            if (!Schema::hasColumn('reference_ranges', 'observation_id')) {
                $table->foreignId('observation_id')->nullable()->constrained('observations')->cascadeOnDelete();
            }

            if (!Schema::hasColumn('reference_ranges', 'observation_component_id')) {
                $table->foreignId('observation_component_id')->nullable()->constrained('observation_components')->cascadeOnDelete();
            }

            if (Schema::hasColumn('reference_ranges', 'referenceable_type')) {
                $table->dropMorphs('referenceable');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('reference_ranges', static function (Blueprint $table) {
            if (!Schema::hasColumn('reference_ranges', 'referenceable_type')) {
                $table->nullableMorphs('referenceable');
            }

            if (Schema::hasColumn('reference_ranges', 'observation_id')) {
                $table->dropForeign(['observation_id']);
                $table->dropColumn('observation_id');
            }

            if (Schema::hasColumn('reference_ranges', 'observation_component_id')) {
                $table->dropForeign(['observation_component_id']);
                $table->dropColumn('observation_component_id');
            }
        });
    }
};
