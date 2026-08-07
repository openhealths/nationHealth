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
        Schema::table('care_plan_activities', function (Blueprint $table) {
            if (!Schema::hasColumn('care_plan_activities', 'quantity_id')) {
                $table->foreignId('quantity_id')->nullable()->constrained('quantities')->onDelete('set null');
            }
            if (!Schema::hasColumn('care_plan_activities', 'daily_amount_id')) {
                $table->foreignId('daily_amount_id')->nullable()->constrained('quantities')->onDelete('set null');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('care_plan_activities', function (Blueprint $table) {
            if (Schema::hasColumn('care_plan_activities', 'quantity_id')) {
                $table->dropForeign(['quantity_id']);
                $table->dropColumn('quantity_id');
            }
            if (Schema::hasColumn('care_plan_activities', 'daily_amount_id')) {
                $table->dropForeign(['daily_amount_id']);
                $table->dropColumn('daily_amount_id');
            }
        });
    }
};
