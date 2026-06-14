<?php

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
        Schema::table('persons', static function (Blueprint $table) {
            if (!Schema::hasColumn('persons', 'is_syncing')) {
                $table->boolean('is_syncing')->default(false)->comment('Indicates whether the person data is currently being synchronized with an eHealth system');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('persons', static function (Blueprint $table) {
            if (Schema::hasColumn('persons', 'is_syncing')) {
                $table->dropColumn('is_syncing');
            }
        });
    }
};
