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
        // Add polymorphic columns to quantities table
        Schema::table('quantities', function (Blueprint $table) {
            if (!Schema::hasColumn('quantities', 'quantifiable_type')) {
                $table->nullableMorphs('quantifiable');
            }
        });

        // Remove the direct foreign key from observations
        Schema::table('observations', function (Blueprint $table) {
            if (Schema::hasColumn('observations', 'value_quantity_id')) {
                $table->dropForeign(['value_quantity_id']);
                $table->dropColumn('value_quantity_id');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Re-add the foreign key column to observations
        Schema::table('observations', function (Blueprint $table) {
            if (!Schema::hasColumn('observations', 'value_quantity_id')) {
                $table->foreignId('value_quantity_id')->nullable()->after('method_id')->constrained('quantities');
            }
        });

        // Remove polymorphic columns from quantities
        Schema::table('quantities', function (Blueprint $table) {
            if (Schema::hasColumn('quantities', 'quantifiable_type')) {
                $table->dropMorphs('quantifiable');
            }
        });
    }
};
