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
        if (!Schema::hasTable('care_plan_activities')) {
            Schema::create('care_plan_activities', function (Blueprint $table) {
                $table->id();
                $table->foreignId('care_plan_id')->constrained('care_plans')->onDelete('cascade');
                $table->foreignId('author_id')->constrained('employees');
                $table->string('status'); // e.g., NEW, scheduled, cancelled, completed
                $table->string('kind'); // e.g., service_request, medication_request, nutrition_order
                $table->string('product_reference')->nullable();
                $table->string('product_codeable_concept')->nullable();
                $table->decimal('quantity', 15, 2)->nullable();
                $table->string('quantity_system')->nullable();
                $table->string('quantity_code')->nullable();
                $table->decimal('daily_amount', 15, 2)->nullable();
                $table->string('daily_amount_system')->nullable();
                $table->string('daily_amount_code')->nullable();
                $table->string('reason_code')->nullable();
                $table->string('reason_reference')->nullable();
                $table->string('goal')->nullable();
                $table->text('description')->nullable();
                $table->string('program')->nullable();
                $table->date('scheduled_period_start')->nullable();
                $table->date('scheduled_period_end')->nullable();
                $table->text('status_reason')->nullable();
                $table->string('outcome_reference')->nullable();
                $table->string('outcome_codeable_concept')->nullable();
                $table->timestamps();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('care_plan_activities');
    }
};
