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
            if (!Schema::hasColumn('reference_ranges', 'age_id')) {
                $table->foreignId('age_id')->nullable()->constrained('ranges');
            }

            if (Schema::hasColumn('reference_ranges', 'age_low_id')) {
                $table->dropForeign(['age_low_id']);
                $table->dropColumn('age_low_id');
            }

            if (Schema::hasColumn('reference_ranges', 'age_high_id')) {
                $table->dropForeign(['age_high_id']);
                $table->dropColumn('age_high_id');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('reference_ranges', static function (Blueprint $table) {
            if (Schema::hasColumn('reference_ranges', 'age_id')) {
                $table->dropForeign(['age_id']);
                $table->dropColumn('age_id');
            }

            if (!Schema::hasColumn('reference_ranges', 'age_low_id')) {
                $table->foreignId('age_low_id')->nullable()->constrained('quantities');
            }

            if (!Schema::hasColumn('reference_ranges', 'age_high_id')) {
                $table->foreignId('age_high_id')->nullable()->constrained('quantities');
            }
        });
    }
};
