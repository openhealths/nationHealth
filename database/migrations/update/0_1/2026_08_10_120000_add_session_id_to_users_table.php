<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migration.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::table('users', static function (Blueprint $table): void {
            if (!Schema::hasColumn('users', 'session_id')) {
                $table
                    ->string('session_id')
                    ->nullable()
                    ->index()
                    ->after('remember_token');
            }
        });
    }

    /**
     * Reverse the migration.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::table('users', static function (Blueprint $table): void {
            if (Schema::hasColumn('users', 'session_id')) {
                $table->dropColumn('session_id');
            }
        });
    }
};
