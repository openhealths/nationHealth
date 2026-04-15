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
        Schema::create('condition_body_sites', static function (Blueprint $table) {
            $table->id();
            $table->foreignId('condition_id')->constrained('conditions')->cascadeOnDelete();
            $table->foreignId('codeable_concept_id')->constrained('codeable_concepts')->cascadeOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('condition_body_sites');
    }
};
