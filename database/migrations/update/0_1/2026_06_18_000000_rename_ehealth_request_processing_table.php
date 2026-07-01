<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable('ehealth_request_processing') && !Schema::hasTable('ehealth_request_processings')) {
            Schema::rename('ehealth_request_processing', 'ehealth_request_processings');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('ehealth_request_processings') && !Schema::hasTable('ehealth_request_processing')) {
            Schema::rename('ehealth_request_processings', 'ehealth_request_processing');
        }
    }
};
