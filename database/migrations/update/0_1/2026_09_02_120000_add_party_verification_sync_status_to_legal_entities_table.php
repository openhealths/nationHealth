<?php

declare(strict_types=1);

use App\Enums\JobStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('legal_entities', static function (Blueprint $table) {
            if (!Schema::hasColumn('legal_entities', 'party_verification_sync_status')) {
                $table->enum('party_verification_sync_status', JobStatus::values())
                    ->nullable()
                    ->after('employee_request_sync_status');
            }
        });
    }

    public function down(): void
    {
        Schema::table('legal_entities', static function (Blueprint $table) {
            if (Schema::hasColumn('legal_entities', 'party_verification_sync_status')) {
                $table->dropColumn('party_verification_sync_status');
            }
        });
    }
};
