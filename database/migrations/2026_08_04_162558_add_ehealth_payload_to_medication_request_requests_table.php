<?php

declare(strict_types=1);

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
        Schema::table('medication_request_requests', function (Blueprint $table) {
            if (!Schema::hasColumn('medication_request_requests', 'ehealth_payload')) {
                $table->json('ehealth_payload')->nullable();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('medication_request_requests', function (Blueprint $table) {
            if (Schema::hasColumn('medication_request_requests', 'ehealth_payload')) {
                $table->dropColumn('ehealth_payload');
            }
        });
    }
};
