<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (!Schema::hasTable('approvals')) {
            Schema::create('approvals', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                
                // Polymorphic relation
                $table->morphs('approvable');
                
                $table->foreignId('granted_to_id')->nullable()->constrained('legal_entities');
                $table->string('granted_to_type')->default('legal_entity');
                
                $table->foreignId('granted_by_id')->nullable()->constrained('employees');
                $table->string('status')->index();
                
                $table->text('reason')->nullable();

                $table->timestamps();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('approvals')) {
            Schema::dropIfExists('approvals');
        }
    }
};
