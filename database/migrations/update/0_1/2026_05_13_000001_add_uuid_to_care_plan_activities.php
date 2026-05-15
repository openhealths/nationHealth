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
            if (!Schema::hasColumn('care_plan_activities', 'uuid')) {
                $table->uuid('uuid')->nullable()->index()->after('id');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('care_plan_activities', function (Blueprint $table) {
            if (Schema::hasColumn('care_plan_activities', 'uuid')) {
                $table->dropColumn('uuid');
            }
        });
    }
};
