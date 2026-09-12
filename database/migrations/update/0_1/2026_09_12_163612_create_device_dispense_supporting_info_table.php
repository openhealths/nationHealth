<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up(): void
    {
        if (!Schema::hasTable('device_dispense_supporting_info')) {
            Schema::create('device_dispense_supporting_info', static function (Blueprint $table) {
                $table->id();
                $table->foreignId('device_dispense_id')->constrained('device_dispenses')->cascadeOnDelete();
                $table->foreignId('identifier_id')->constrained('identifiers')->cascadeOnDelete();
                $table->timestamps();
            });
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('device_dispense_supporting_info');
    }
};