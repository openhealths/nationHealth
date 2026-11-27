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
        Schema::table('client_connections', static function (Blueprint $table): void {
            if (!Schema::hasColumn('client_connections', 'client_uuid')) {
                $table->uuid('client_uuid')->nullable()->comment('Client UUID at the eHealth side');
            }

            if (Schema::hasColumn('client_connections', 'uuid')) {
                $table->uuid()->comment('Connection UUID at the eHealth side')->change();
            }

            if (!Schema::hasIndex('client_connections', 'client_connections_unique')) {
                $table->unique(['uuid', 'legal_entity_id'], 'client_connections_unique');
            }

            if (!Schema::hasColumn('client_connections', 'secret')) {
                $table->string('secret')->nullable()->comment('Legal Entity connection token');
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
        Schema::table('client_connections', static function (Blueprint $table): void {
            if (Schema::hasColumn('client_connections', 'client_uuid')) {
                $table->dropColumn('client_uuid');
            }

            if (Schema::hasIndex('client_connections', 'client_connections_unique')) {
                $table->dropUnique('client_connections_unique');
            }

            if (Schema::hasColumn('client_connections', 'secret')) {
                $table->dropColumn('secret');
            }
        });
    }
};
