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
            if (!Schema::hasColumn('legal_entities', 'equipment_sync_status')) {
                $table->enum('equipment_sync_status', JobStatus::values())->nullable();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('legal_entities', function (Blueprint $table) {
            if (Schema::hasColumn('legal_entities', 'equipment_sync_status')) {
                $table->dropColumn('equipment_sync_status');
            }
        });
    }
};
