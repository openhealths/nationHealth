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
        Schema::table('quantities', static function (Blueprint $table) {
            if (Schema::hasColumn('quantities', 'quantifiable_type')) {
                $table->dropMorphs('quantifiable');
            }
        });

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('quantities', static function (Blueprint $table) {
            if (!Schema::hasColumn('quantities', 'quantifiable_type')) {
                $table->nullableMorphs('quantifiable');
            }
        });
    }
};
