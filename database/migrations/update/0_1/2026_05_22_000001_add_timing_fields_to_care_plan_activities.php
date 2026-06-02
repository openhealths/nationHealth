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
            if (!Schema::hasColumn('care_plan_activities', 'quantity_per_time')) {
                $table->decimal('quantity_per_time', 15, 2)->nullable()->after('daily_amount_code');
            }
            if (!Schema::hasColumn('care_plan_activities', 'quantity_per_time_unit')) {
                $table->string('quantity_per_time_unit')->nullable()->after('quantity_per_time');
            }
            if (!Schema::hasColumn('care_plan_activities', 'frequency')) {
                $table->integer('frequency')->nullable()->after('quantity_per_time_unit');
            }
            if (!Schema::hasColumn('care_plan_activities', 'frequency_unit')) {
                $table->string('frequency_unit')->nullable()->after('frequency');
            }
            if (!Schema::hasColumn('care_plan_activities', 'duration')) {
                $table->integer('duration')->nullable()->after('frequency_unit');
            }
            if (!Schema::hasColumn('care_plan_activities', 'duration_unit')) {
                $table->string('duration_unit')->nullable()->after('duration');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('care_plan_activities', function (Blueprint $table) {
            $columns = [
                'quantity_per_time',
                'quantity_per_time_unit',
                'frequency',
                'frequency_unit',
                'duration',
                'duration_unit',
            ];
            foreach ($columns as $column) {
                if (Schema::hasColumn('care_plan_activities', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
