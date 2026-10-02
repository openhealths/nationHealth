<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_request_requests', static function (Blueprint $table): void {
            if (!Schema::hasColumn('service_request_requests', 'performer_legal_entity_uuid')) {
                $table->uuid('performer_legal_entity_uuid')->nullable()->after('supporting_info');
            }
            if (!Schema::hasColumn('service_request_requests', 'location_reference_uuid')) {
                $table->uuid('location_reference_uuid')->nullable()->after('performer_legal_entity_uuid');
            }
            if (!Schema::hasColumn('service_request_requests', 'performer_type')) {
                $table->string('performer_type')->nullable()->after('location_reference_uuid');
            }
        });
    }

    public function down(): void
    {
        Schema::table('service_request_requests', static function (Blueprint $table): void {
            $columns = array_values(array_filter([
                Schema::hasColumn('service_request_requests', 'performer_legal_entity_uuid') ? 'performer_legal_entity_uuid' : null,
                Schema::hasColumn('service_request_requests', 'location_reference_uuid') ? 'location_reference_uuid' : null,
                Schema::hasColumn('service_request_requests', 'performer_type') ? 'performer_type' : null,
            ]));

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
