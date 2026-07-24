<?php

declare(strict_types=1);

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
        Schema::table('reorganization_employee_declarations', function (Blueprint $table) {
            if (!Schema::hasColumn('reorganization_employee_declarations', 'id')) {
                $table->id()->first();
            }

            if (!Schema::hasColumn('reorganization_employee_declarations', 'declaration_request_id')) {
                $table->foreignId('declaration_request_id')->nullable()->constrained('declaration_requests')->cascadeOnDelete();
            }

            if (!Schema::hasColumn('reorganization_employee_declarations', 'declaration_request_uuid')) {
                $table->uuid('declaration_request_uuid')->nullable()->comment('reorganized declaration request uuid');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('reorganization_employee_declarations', function (Blueprint $table) {
            if (Schema::hasColumn('reorganization_employee_declarations', 'id')) {
                $table->dropColumn('id');
            }

            if (Schema::hasColumn('reorganization_employee_declarations', 'declaration_request_id')) {
                $table->dropForeign(['declaration_request_id']);
            }

            if (Schema::hasColumn('reorganization_employee_declarations', 'declaration_request_uuid')) {
                $table->dropColumn(['declaration_request_id', 'declaration_request_uuid']);
            }
        });
    }
};
