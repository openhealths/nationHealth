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
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'inserted_at')) {
                $table->timestamp('inserted_at')->nullable();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
         Schema::table('users', function (Blueprint $table) {
            // Check if 'inserted_at' column exists before attempting to drop it
            if (Schema::hasColumn('users', 'inserted_at')) {
                $table->dropColumn('inserted_at');
            }
        });
    }
};
