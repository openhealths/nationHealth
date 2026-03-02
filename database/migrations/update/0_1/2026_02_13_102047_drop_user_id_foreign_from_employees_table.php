<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            // Check if 'user_id' column exists before attempting to drop it
            if (Schema::hasColumn('employees', 'user_id')) {
                // Check if foreign key exists
                $foreignKeys = Schema::getForeignKeys('employees');

                $isForeignKeyPresent = collect($foreignKeys)->contains(function ($fk) {
                    return in_array('user_id', $fk['columns']);
                });

                if ($isForeignKeyPresent) {
                    $table->dropForeign(['user_id']);
                }

                $table->dropColumn('user_id');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            if (!Schema::hasColumn('employees', 'user_id')) {
                $table->foreignId('user_id')->nullable()->constrained('users')->onDelete('cascade');
            }
        });
    }
};
