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
        if (!Schema::hasTable('client_connections')) {
            Schema::create('client_connections', function (Blueprint $table) {
                $table->id();
                $table->uuid();
                $table->foreignId('legal_entity_id')->constrained('legal_entities')->cascadeOnDelete();
                $table->uuid('consumer_uuid')->nullable();
                $table->string('redirect_uri')->nullable();

                $table->date('ehealth_inserted_at')->nullable();
                $table->date('ehealth_updated_at')->nullable();

                $table->timestamps();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('client_connections');
    }
};
