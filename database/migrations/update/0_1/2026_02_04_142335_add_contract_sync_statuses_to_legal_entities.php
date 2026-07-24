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
        // 1. Updating the table of Contracts
        Schema::table('contracts', function (Blueprint $table) {
            if (!Schema::hasColumn('contracts', 'sync_status')) {
                $table->enum('sync_status', JobStatus::values())
                    ->default(JobStatus::COMPLETED->value)
                    ->nullable()
                    ->after('status');
            }
        });

        // 2. Оновлюємо таблицю Заявок на договори
        Schema::table('contract_requests', function (Blueprint $table) {
            if (!Schema::hasColumn('contract_requests', 'sync_status')) {
                $table->enum('sync_status', JobStatus::values())
                    ->default(JobStatus::COMPLETED->value)
                    ->nullable()
                    ->after('status');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            if (Schema::hasColumn('contracts', 'sync_status')) {
                $table->dropColumn('sync_status');
            }
        });

        Schema::table('contract_requests', function (Blueprint $table) {
            if (Schema::hasColumn('contract_requests', 'sync_status')) {
                $table->dropColumn('sync_status');
            }
        });
    }
};
