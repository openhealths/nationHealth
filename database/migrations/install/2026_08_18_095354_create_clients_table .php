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
        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('legal_entity_id')->constrained('legal_entities')->cascadeOnDelete();
            $table->uuid('user_uuid')->nullable();
            $table->foreignId('legal_entity_type_id')->constrained('legal_entity_types')->cascadeOnDelete();
            $table->string('name')->nullable();
            $table->json('settings')->nullable();
            $table->boolean('is_blocked')->default(false);
            $table->string('block_reason')->nullable();

            $table->date('ehealth_inserted_at')->nullable();
            $table->date('ehealth_updated_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clients');
    }
};
