<?php

declare(strict_types=1);

use RuntimeException;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_request_requests', static function (Blueprint $table): void {
            $table->foreignId('used_by_employee_id')->nullable()->constrained('identifiers');
            $table->foreignId('used_by_legal_entity_id')->nullable()->constrained('identifiers');
            $table->foreignId('completed_with_id')->nullable()->constrained('identifiers');
            $table->jsonb('status_history')->nullable();
            $table->timestampTz('expiration_date')->nullable();
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Referral execution history requires a forward repair to preserve clinical records.');
    }
};
