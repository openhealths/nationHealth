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
        Schema::table('identifiers', static function (Blueprint $table) {
            if (!Schema::hasColumn('identifiers', 'display_value')) {
                $table->string('display_value')->after('value')->nullable();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('identifiers', static function (Blueprint $table) {
            if (Schema::hasColumn('identifiers', 'display_value')) {
                $table->dropColumn('display_value');
            }
        });
    }
};
