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
        Schema::create('approvals', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique()->nullable();
            
            // Polymorphic relation
            $table->morphs('approvable');
            
            $table->foreignId('created_by_id')->nullable()->constrained('identifiers');

            $table->foreignId('granted_to_id')->nullable()->constrained('identifiers');
            $table->string('granted_to_type')->default('legal_entity');
            
            $table->foreignId('granted_by_id')->nullable()->constrained('employees');
            
            $table->uuid('authorize_with')->nullable();
            $table->foreignId('authentication_method_id')->nullable()->constrained('authentication_methods');
            
            $table->foreignId('reason_id')->nullable()->constrained('identifiers');

            $table->string('status')->index();
            $table->string('access_level')->default('read');
            $table->boolean('is_verified')->default(false);
            $table->timestamp('expires_at')->nullable();

            $table->timestamps();
        });

        Schema::create('approval_granted_resources', static function (Blueprint $table) {
            $table->id();
            $table->foreignId('approval_id')->constrained('approvals')->cascadeOnDelete();
            $table->foreignId('granted_to_id')->nullable()->constrained('identifiers');
            $table->timestamps();
        });

        Schema::create('approval_granted_resource_types', static function (Blueprint $table) {
            $table->id();
            $table->foreignId('approval_id')->constrained('approvals')->cascadeOnDelete();
            $table->foreignId('codeable_concept_id')->constrained('codeable_concepts')->cascadeOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('approval_granted_resource_types');
        Schema::dropIfExists('approval_granted_resources');
        Schema::dropIfExists('approvals');
    }
};
