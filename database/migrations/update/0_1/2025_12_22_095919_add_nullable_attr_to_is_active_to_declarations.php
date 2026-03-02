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
        Schema::table('declarations', function (Blueprint $table) {
            if (!Schema::hasColumn('declarations', 'sync_status')) {
                $table->enum('sync_status', JobStatus::values())->nullable();

            }

            $table->boolean('is_active')->nullable()->default(false)->change();
        });
    }

    /**
     * Reverse the migrations.
     * Remove default value for is_active column
     */
    public function down(): void
    {
        Schema::table('declarations', function (Blueprint $table) {
            if (Schema::hasColumn('declarations', 'sync_status')) {
                $table->dropColumn('sync_status');
            }

            $table->date('issued_date')->nullable(false)->default(null)->change();
        });
    }
};
