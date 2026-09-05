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
        Schema::table('legal_entity_types', static function (Blueprint $table): void {
            if (!Schema::hasColumn('legal_entity_types', 'uuid')) {
                $table->uuid()->nullable()->comment('Legal Entity Type UUID at the eHealth side');
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
        Schema::table('legal_entity_types', static function (Blueprint $table): void {
            if (Schema::hasColumn('legal_entity_types', 'uuid')) {
                $table->dropColumn('uuid');
            }
        });
    }
};
