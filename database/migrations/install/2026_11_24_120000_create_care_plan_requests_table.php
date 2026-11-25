<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Install the local store for applications to create care plans. */
return new class extends Migration {
    /** Create the request table and its patient, facility and user foreign keys. */
    public function up(): void
    {
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

    /** Drop the local request store on rollback. */
    public function down(): void
    {
        Schema::dropIfExists('care_plan_requests');
    }
};
