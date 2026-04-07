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
        Schema::table('clinical_impressions', static function (Blueprint $table) {
            if (Schema::hasColumn('clinical_impressions', 'encounter_internal_id')) {
                $table->foreignId('encounter_internal_id')->nullable()->change();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('clinical_impressions', static function (Blueprint $table) {
            if (Schema::hasColumn('clinical_impressions', 'encounter_internal_id')) {
                $table->foreignId('encounter_internal_id')->nullable(false)->change();
            }
        });
    }
};
