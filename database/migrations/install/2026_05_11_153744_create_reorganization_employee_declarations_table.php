<?php

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
        Schema::create('reorganization_employee_declarations', function (Blueprint $table) {
            $table->foreignId('legal_entity_id')->nullable()->constrained('legal_entities')->cascadeOnDelete();
            $table->uuid('legal_entity_uuid')->comment('reorganized legal entity uuid');
            $table->foreignId('employee_id')->nullable()->constrained('employees')->cascadeOnDelete();
            $table->uuid('employee_uuid')->comment('reorganized employee uuid');
            $table->foreignId('party_id')->nullable()->constrained('parties')->cascadeOnDelete();
            $table->uuid('party_uuid')->comment('reorganized employee\'s party uuid');
            $table->foreignId('person_id')->nullable()->constrained('persons')->cascadeOnDelete()->cascadeOnUpdate();
            $table->uuid('person_uuid')->nullable()->comment('reorganized person uuid');
            $table->foreignId('declaration_id')->nullable()->constrained('declarations')->cascadeOnDelete()->cascadeOnUpdate();
            $table->uuid('declaration_uuid')->nullable()->comment('reorganized declaration uuid');
            $table->string('declaration_number')->nullable()->comment('reorganized declaration number');
            $table->string('authorize_with')->nullable()->comment('reorganized declaration request authorize with');

            $table->timestamps();

            // Prevent duplicates
            $table->unique([
                'legal_entity_uuid',
                'employee_uuid',
                'declaration_uuid',
            ], 'reorg_employee_declaration_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reorganization_employee_declarations');
    }
};
