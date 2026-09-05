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
            if (!Schema::hasColumn('service_request_requests', 'patient_instruction')) {
                $table->text('patient_instruction')->nullable()->after('note');
            }
            if (!Schema::hasColumn('service_request_requests', 'reason_reference')) {
                $table->json('reason_reference')->nullable()->after('patient_instruction');
            }
            if (!Schema::hasColumn('service_request_requests', 'inform_with')) {
                $table->string('inform_with')->nullable()->after('reason_reference');
            }
        });
    }

    public function down(): void
    {
        Schema::table('service_request_requests', static function (Blueprint $table): void {
            $columns = array_values(array_filter([
                Schema::hasColumn('service_request_requests', 'patient_instruction') ? 'patient_instruction' : null,
                Schema::hasColumn('service_request_requests', 'reason_reference') ? 'reason_reference' : null,
                Schema::hasColumn('service_request_requests', 'inform_with') ? 'inform_with' : null,
            ]));

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
