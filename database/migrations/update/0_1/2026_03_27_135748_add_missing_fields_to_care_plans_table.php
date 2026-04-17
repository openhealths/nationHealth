<?php

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
        Schema::table('care_plans', function (Blueprint $table) {
            if (!Schema::hasColumn('care_plans', 'clinical_protocol')) {
                $table->string('clinical_protocol')->nullable()->after('category');
            }
            if (!Schema::hasColumn('care_plans', 'context')) {
                $table->string('context')->nullable()->after('clinical_protocol');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('care_plans', function (Blueprint $table) {
            if (Schema::hasColumn('care_plans', 'clinical_protocol')) {
                $table->dropColumn('clinical_protocol');
            }
            if (Schema::hasColumn('care_plans', 'context')) {
                $table->dropColumn('context');
            }
        });
    }
};
