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
        Schema::create('client_connections', function (Blueprint $table) {
            $table->id();
            $table->uuid();
            $table->foreignId('legal_entity_id')->constrained('legal_entities')->cascadeOnDelete();
            $table->uuid('consumer_uuid')->nullable()->comment('MIS UUID at the eHealth side');
            $table->string('redirect_uri')->nullable();

            $table->date('ehealth_inserted_at')->nullable();
            $table->date('ehealth_updated_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_connections');
    }
};
