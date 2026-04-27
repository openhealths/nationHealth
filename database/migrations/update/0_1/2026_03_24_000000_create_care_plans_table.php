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
        if (!Schema::hasTable('care_plans')) {
            Schema::create('care_plans', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->nullable()->index();
                $table->foreignId('person_id')->constrained('persons');
                $table->foreignId('author_id')->constrained('employees');
                $table->foreignId('legal_entity_id')->constrained('legal_entities');
                $table->string('status'); // e.g., draft, active, on-hold, revoked, completed, entered-in-error, unknown
                $table->string('category')->nullable();
                $table->string('title');
                $table->date('period_start');
                $table->date('period_end')->nullable();
                $table->string('terms_of_service')->nullable();
                $table->foreignId('encounter_id')->nullable()->constrained('encounters');
                $table->json('addresses')->nullable();
                $table->text('description')->nullable();
                $table->json('supporting_info')->nullable();
                $table->text('note')->nullable();
                $table->string('inform_with')->nullable();
                $table->string('requisition')->nullable()->index();
                $table->timestamps();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('care_plans');
    }
};
