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
        Schema::create('treatment_plans', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->uuid('ehealth_id')->nullable();
            $table->string('patient_id')->index();
            $table->string('category');
            $table->string('intention');
            $table->string('terms_service');
            $table->string('name_treatment_plan');
            $table->dateTime('period_start');
            $table->dateTime('period_end');
            $table->string('status')->default('draft');
            $table->string('job_id')->nullable();
            $table->json('validation_details')->nullable();
            $table->timestamps();
            $table->string('inserted_by')->nullable();
            $table->string('updated_by')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('treatment_plans');
    }
};
