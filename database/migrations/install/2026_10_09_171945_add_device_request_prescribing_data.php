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
        Schema::table('device_request_requests', static function (Blueprint $table): void {
            $table->decimal('quantity', 15, 2)->nullable()->default(null)->change();
            $table->json('request_payload')->nullable();
            $table->json('remote_details')->nullable();
            $table->timestamp('sms_resent_at')->nullable();
        });
        Schema::create('device_request_sms_resends', static function (Blueprint $table): void {
            $table->uuid('device_request_uuid')->primary();
            $table->uuid('person_uuid');
            $table->timestamp('attempted_at');
            $table->timestamp('confirmed_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('device_request_sms_resends');
        Schema::table('device_request_requests', static function (Blueprint $table): void {
            $table->dropColumn(['request_payload', 'remote_details', 'sms_resent_at']);
        });
        // Keep nullable quantities: rollback must not invent a clinical quantity for existing requests.
    }
};
