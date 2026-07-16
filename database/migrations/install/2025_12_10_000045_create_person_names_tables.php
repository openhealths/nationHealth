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
        Schema::create('person_names', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('person_id')->constrained('persons')->cascadeOnDelete();
            $table->string('language');
            $table->string('first_name');
            $table->string('last_name')->nullable();
            $table->string('second_name')->nullable();
            $table->boolean('no_last_name')->default(false);
            $table->timestamps();
        });

        Schema::create('person_request_names', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('person_request_id')->constrained('person_requests')->cascadeOnDelete();
            $table->string('language');
            $table->string('first_name');
            $table->string('last_name')->nullable();
            $table->string('second_name')->nullable();
            $table->boolean('no_last_name')->default(false);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('person_request_names');
        Schema::dropIfExists('person_names');
    }
};
