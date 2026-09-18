<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Record which request an outstanding async job belongs to.
 *
 * Create, sign, cancel and the ERLN retry all answer with the same kind of job, but the
 * local consequence of a finished job differs for each: a finished cancellation moves
 * the conclusion to ENTERED_IN_ERROR, a finished retry only refreshes the integration
 * status. Without knowing which request a job came from, the poller cannot act on it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('compositions', 'async_job_operation')) {
            return;
        }

        Schema::table('compositions', static function (Blueprint $table): void {
            $table->string('async_job_operation')
                ->nullable()
                ->after('async_job_status')
                ->comment('CREATE | SIGN | CANCEL | ERLN_RETRY — what the pending job was requested for');

            $table->text('async_job_error')
                ->nullable()
                ->after('async_job_operation')
                ->comment('Message reported by eHealth when the job failed');
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('compositions', 'async_job_operation')) {
            return;
        }

        Schema::table('compositions', static function (Blueprint $table): void {
            $table->dropColumn(['async_job_operation', 'async_job_error']);
        });
    }
};
