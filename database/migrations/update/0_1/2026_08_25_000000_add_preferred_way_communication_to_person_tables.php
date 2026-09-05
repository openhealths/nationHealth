<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Keep the preferred way of communication that is sent to eHealth with the person request.
     */
    public function up(): void
    {
        foreach (['persons', 'person_requests'] as $tableName) {
            if (!Schema::hasTable($tableName) || Schema::hasColumn($tableName, 'preferred_way_communication')) {
                continue;
            }

            Schema::table($tableName, static function (Blueprint $table) {
                $table->string('preferred_way_communication')->nullable()->after('email');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (['persons', 'person_requests'] as $tableName) {
            if (!Schema::hasTable($tableName) || !Schema::hasColumn($tableName, 'preferred_way_communication')) {
                continue;
            }

            Schema::table($tableName, static function (Blueprint $table) {
                $table->dropColumn('preferred_way_communication');
            });
        }
    }
};
