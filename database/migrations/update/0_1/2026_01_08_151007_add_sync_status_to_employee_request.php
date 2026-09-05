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
        Schema::table('employee_requests', function (Blueprint $table) {
            if (!Schema::hasColumn('employee_requests', 'sync_status')) {
                $table->enum('sync_status', JobStatus::values())->nullable();
            }

            $table->string('position')->nullable()->change();
            $table->date('start_date')->nullable()->change();
            $table->string('employee_type')->nullable()->change();
            $table->string('email')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('declaration_requests', function (Blueprint $table) {
            if (Schema::hasColumn('employee_requests', 'sync_status')) {
                $table->dropColumn('sync_status');
            }

            $table->string('position')->nullable(false)->change();
            $table->date('start_date')->nullable(false)->change();
            $table->string('employee_type')->nullable(false)->change();
            $table->string('email')->nullable(false)->change();
        });
    }
};
