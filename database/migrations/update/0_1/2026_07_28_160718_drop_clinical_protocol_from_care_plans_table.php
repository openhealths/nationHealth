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
        if (Schema::hasTable('care_plans') && Schema::hasColumn('care_plans', 'clinical_protocol')) {
            Schema::table('care_plans', function (Blueprint $table) {
                $table->dropColumn('clinical_protocol');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('care_plans', function (Blueprint $table) {
            $table->string('clinical_protocol')->nullable();
        });
    }
};
