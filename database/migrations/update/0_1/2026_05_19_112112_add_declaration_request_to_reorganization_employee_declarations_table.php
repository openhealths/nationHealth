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
        Schema::table('reorganization_employee_declarations', function (Blueprint $table) {
            if (!Schema::hasColumn('reorganization_employee_declarations', 'id')) {
                // FIRST belongs to ALTER TABLE; the install migration already declares id first.
                $table->id()->first();
            }

            if (!Schema::hasColumn('reorganization_employee_declarations', 'declaration_request_id')) {
                $table->foreignId('declaration_request_id')->nullable()->constrained(
                    'declaration_requests',
                    indexName: 'reorg_employee_declarations_request_fk'
                )->cascadeOnDelete();
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
                foreach (Schema::getForeignKeys('reorganization_employee_declarations') as $foreignKey) {
                    if ($foreignKey['columns'] === ['declaration_request_id']) {
                        $table->dropForeign($foreignKey['name']);

                        break;
                    }
                }
            }

            if (Schema::hasColumn('reorganization_employee_declarations', 'declaration_request_uuid')) {
                $table->dropColumn(['declaration_request_id', 'declaration_request_uuid']);
            }
        });
    }
};
