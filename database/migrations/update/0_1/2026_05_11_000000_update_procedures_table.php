<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('procedures', static function (Blueprint $table) {
            if (Schema::hasColumn('procedures', 'encounter_internal_id')) {
                $table->dropForeign(['encounter_internal_id']);
                $table->dropColumn('encounter_internal_id');
            }

            if (!Schema::hasColumn('procedures', 'person_id')) {
                $table->foreignId('person_id')->after('uuid')->constrained('persons');
            }
        });
    }

    public function down(): void
    {
        Schema::table('procedures', static function (Blueprint $table) {
            if (Schema::hasColumn('procedures', 'person_id')) {
                $table->dropForeign(['person_id']);
                $table->dropColumn('person_id');
            }

            if (!Schema::hasColumn('procedures', 'encounter_internal_id')) {
                $table->foreignId('encounter_internal_id')->nullable()->after('uuid')->constrained('encounters');
            }
        });
    }
};
