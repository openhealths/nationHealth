<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('care_plans', static function (Blueprint $table): void {
            $table->json('status_history')->nullable();
            $table->timestampTz('ehealth_inserted_at')->nullable();
            $table->timestampTz('ehealth_updated_at')->nullable();
            $table->uuid('ehealth_inserted_by')->nullable();
            $table->uuid('ehealth_updated_by')->nullable();
        });

        Schema::create('care_plan_requests', static function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained();
            $table->foreignId('legal_entity_id')->constrained();
            $table->foreignId('person_id')->constrained('persons');
            $table->foreignId('care_plan_id')->nullable()->unique()->constrained();
            $table->unsignedInteger('version');
            $table->uuid('condition_uuid')->nullable();
            $table->uuid('author_uuid')->nullable();
            $table->json('addresses');
            $table->uuid('encounter');
            $table->text('title');
            $table->text('category');
            $table->text('context');
            $table->text('terms_of_service');
            $table->date('period_start');
            $table->date('period_end')->nullable();
            $table->string('period_start_time');
            $table->string('period_end_time');
            $table->json('episodes');
            $table->json('medical_records');
            $table->json('co_authors');
            $table->text('description');
            $table->text('note');
            $table->text('inform_with');
            $table->timestamps();
            $table->index(['legal_entity_id', 'user_id', 'care_plan_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('care_plan_requests');
        Schema::table('care_plans', static function (Blueprint $table): void {
            $table->dropColumn(['status_history', 'ehealth_inserted_at', 'ehealth_updated_at', 'ehealth_inserted_by', 'ehealth_updated_by']);
        });
    }
};
