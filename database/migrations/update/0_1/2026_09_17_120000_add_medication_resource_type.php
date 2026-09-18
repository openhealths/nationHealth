<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('medication_request_requests', 'resource_type')) {
            Schema::table('medication_request_requests', static function (Blueprint $table) {
                $table->string('resource_type')->default('medication_request_request');
            });

            // Keep previously cached records in their existing tab until refreshed from their API endpoint.
            DB::table('medication_request_requests')->where('source', 'ehealth')
                ->update(['resource_type' => 'medication_request']);
        }

        Schema::table('medication_request_requests', static function (Blueprint $table) {
            $table->string('medication_id')->nullable()->change();
            $table->decimal('medication_qty', 15, 2)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('medication_request_requests', static function (Blueprint $table) {
            $table->dropColumn('resource_type');
        });
        // Keep nullable summary fields: rolling back must not fabricate medication quantities.
    }
};
