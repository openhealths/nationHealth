<?php

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
        Schema::table('ehealth_links', static function (Blueprint $table) {
            if (!Schema::hasColumn('ehealth_links', 'status')) {
                $table->enum('status', JobStatus::values())->default(JobStatus::PENDING)->nullable()->comment('Status of the link processing, e.g., pending, processed, failed');
            }

            if (!Schema::hasColumn('ehealth_links', 'error')) {
                $table->json('error')->nullable()->comment('Error details if the link creation or processing failed');
            }

            if (!Schema::hasColumn('ehealth_links', 'error_code')) {
                $table->string('error_code')->nullable()->comment('Error code if the link creation or processing failed');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ehealth_links', static function (Blueprint $table) {
            if (Schema::hasColumn('ehealth_links', 'status')) {
                $table->dropColumn('status');
            }

            if (Schema::hasColumn('ehealth_links', 'error')) {
                $table->dropColumn('error');
            }

            if (Schema::hasColumn('ehealth_links', 'error_code')) {
                $table->dropColumn('error_code');
            }
        });
    }
};
