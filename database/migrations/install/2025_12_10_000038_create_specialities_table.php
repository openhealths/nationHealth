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
        Schema::create('specialities', function (Blueprint $table) {
            $table->id();
            $table->string('speciality');
            $table->boolean('speciality_officio')->comment('Is main speciality');
            $table->string('level');
            $table->string('qualification_type')->nullable();
            $table->string('attestation_name');
            $table->date('attestation_date');
            $table->date('valid_to_date')->nullable();
            $table->string('certificate_number');
            $table->morphs('specialityable');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('specialities');
    }
};
