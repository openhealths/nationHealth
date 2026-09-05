<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('care_plan_activities', function (Blueprint $table) {
            if (!Schema::hasColumn('care_plan_activities', 'remaining_quantity')) {
                $table->decimal('remaining_quantity', 12, 4)->nullable()->after('daily_amount_code');
            }
            if (!Schema::hasColumn('care_plan_activities', 'remaining_quantity_system')) {
                $table->string('remaining_quantity_system')->nullable()->after('remaining_quantity');
            }
            if (!Schema::hasColumn('care_plan_activities', 'remaining_quantity_code')) {
                $table->string('remaining_quantity_code')->nullable()->after('remaining_quantity_system');
            }
        });
    }

    public function down(): void
    {
        Schema::table('care_plan_activities', function (Blueprint $table) {
            $columns = array_values(array_filter([
                Schema::hasColumn('care_plan_activities', 'remaining_quantity') ? 'remaining_quantity' : null,
                Schema::hasColumn('care_plan_activities', 'remaining_quantity_system') ? 'remaining_quantity_system' : null,
                Schema::hasColumn('care_plan_activities', 'remaining_quantity_code') ? 'remaining_quantity_code' : null,
            ]));

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
