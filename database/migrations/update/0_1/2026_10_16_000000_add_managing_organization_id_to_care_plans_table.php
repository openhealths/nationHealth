<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up(): void
    {
        if (Schema::hasTable('care_plans') && !Schema::hasColumn('care_plans', 'managing_organization_id')) {
            Schema::table('care_plans', function (Blueprint $table) {
                $table->foreignId('managing_organization_id')
                    ->nullable()
                    ->after('care_manager_id')
                    ->constrained('identifiers');
            });
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down(): void
    {
        if (Schema::hasTable('care_plans') && Schema::hasColumn('care_plans', 'managing_organization_id')) {
            Schema::table('care_plans', function (Blueprint $table) {
                $table->dropForeign(['managing_organization_id']);
                $table->dropColumn('managing_organization_id');
            });
        }
    }
};
