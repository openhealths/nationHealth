<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add source column to medication_request_requests to distinguish locally-created drafts
 * from records that were fetched from eHealth and upserted for display on the patient card.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('medication_request_requests', static function (Blueprint $table) {
            $table->string('source')->default('local')->after('ehealth_payload');
        });
    }

    public function down(): void
    {
        Schema::table('medication_request_requests', static function (Blueprint $table) {
            $table->dropColumn('source');
        });
    }
};
