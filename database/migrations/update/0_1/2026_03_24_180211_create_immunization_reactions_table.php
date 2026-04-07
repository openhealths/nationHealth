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
        if (!Schema::hasTable('immunization_reactions')) {
            Schema::create('immunization_reactions', static function (Blueprint $table) {
                $table->id();
                $table->foreignId('immunization_id')->constrained('immunizations')->cascadeOnDelete();
                $table->foreignId('detail_id')->constrained('identifiers')->cascadeOnDelete();
                $table->string('display_value')->nullable();
                $table->timestamps();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('immunization_reactions');
    }
};
