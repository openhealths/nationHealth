<?php

declare(strict_types=1);

use App\Enums\JobStatus;
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
        Schema::table('legal_entities', function (Blueprint $table) {
            if (!Schema::hasColumn('legal_entities', 'contract_sync_status')) {
                $table->enum('contract_sync_status', JobStatus::values())
                    ->nullable()
                    ->after('equipment_sync_status');
            }

            if (!Schema::hasColumn('legal_entities', 'contract_request_sync_status')) {
                $table->enum('contract_request_sync_status', JobStatus::values())
                    ->nullable()
                    ->after('contract_sync_status');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('legal_entities', function (Blueprint $table) {
            $table->dropColumn(['contract_sync_status', 'contract_request_sync_status']);
        });
    }
};
