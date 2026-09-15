<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use App\Enums\LegalEntity\ConnectionStatus;
use Illuminate\Database\Migrations\Migration;

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
            if (!Schema::hasColumn('client_connections', 'status')) {
                $table->string('status')->default(ConnectionStatus::ACTIVE)->comment('Connection status at the MIS side');
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
            if (Schema::hasColumn('client_connections', 'status')) {
                $table->dropColumn('status');
            }
        });
    }
};
