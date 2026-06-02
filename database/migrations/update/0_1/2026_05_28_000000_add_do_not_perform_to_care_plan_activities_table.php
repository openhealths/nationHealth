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
            if (! Schema::hasColumn('care_plan_activities', 'do_not_perform')) {
                $table->boolean('do_not_perform')->default(false);
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('care_plan_activities', function (Blueprint $table) {
            if (Schema::hasColumn('care_plan_activities', 'do_not_perform')) {
                $table->dropColumn('do_not_perform');
            }
        });
    }
};
