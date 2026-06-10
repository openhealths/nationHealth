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
        Schema::create('ehealth_request_processings', function (Blueprint $table) {
            $table->id();

            $table->foreignId('ehealth_link_id')->nullable()
                ->constrained('ehealth_links')
                ->nullOnDelete();

            $table->json('response_data')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ehealth_request_processings');
    }
};
