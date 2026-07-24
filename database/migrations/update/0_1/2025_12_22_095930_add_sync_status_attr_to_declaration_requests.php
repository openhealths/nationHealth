<?php

declare(strict_types=1);

use App\Enums\JobStatus;
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
        // Set default value for is_active column
        Schema::table('declaration_requests', function (Blueprint $table) {
            if (!Schema::hasColumn('declaration_requests', 'sync_status')) {
                $table->enum('sync_status', JobStatus::values())->nullable();
            }
        });
    }

    /**
     * Reverse the migrations.
     * Remove default value for is_active column
     */
    public function down(): void
    {
        Schema::table('declaration_requests', function (Blueprint $table) {
            if (Schema::hasColumn('declaration_requests', 'sync_status')) {
                $table->dropColumn('sync_status');
            }
        });
    }
};
