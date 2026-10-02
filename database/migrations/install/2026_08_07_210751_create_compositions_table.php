<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Clinical data only; request processing and integrations have their own tables. */
    public function up(): void
    {
        if (Schema::hasTable('compositions')) {
            return;
        }

        Schema::create('compositions', static function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();

            $table->unsignedBigInteger('person_id')->nullable()->index();
            $table->unsignedBigInteger('preperson_id')->nullable()->index();

            $table->string('status')->index();
            $table->string('title')->nullable();
            $table->timestampTz('date')->nullable();

            $table->foreignId('type_id')->nullable()->constrained('codeable_concepts');
            $table->foreignId('category_id')->nullable()->constrained('codeable_concepts');
            $table->foreignId('encounter_id')->nullable()->constrained('identifiers');
            $table->foreignId('author_id')->nullable()->constrained('identifiers');
            $table->foreignId('custodian_id')->nullable()->constrained('identifiers');
            $table->foreignId('subject_id')->nullable()->constrained('identifiers');
            $table->foreignId('section_focus_id')->nullable()->constrained('identifiers');
            $table->foreignId('episode_of_care_id')->nullable()->constrained('identifiers');

            $table->string('relates_to_code')->nullable();
            $table->foreignId('relates_to_target_id')->nullable()->constrained('identifiers');

            $table->uuid('inform_with_uuid')->nullable();
            $table->boolean('is_accident')->default(false);
            $table->boolean('is_intoxicated')->default(false);
            $table->boolean('is_foreign_treatment')->default(false);
            $table->boolean('is_force_renew')->default(false);
            $table->string('treatment_violation')->nullable();
            $table->date('treatment_violation_date')->nullable();
            $table->date('newborn_birth_date')->nullable();
            $table->string('newborn_sex')->nullable();

            $table->timestampTz('ehealth_inserted_at')->nullable();
            $table->timestampTz('ehealth_updated_at')->nullable();
            $table->timestamps();

            // Patient tables can arrive later on a from-scratch install.
            if (Schema::hasTable('persons')) {
                $table->foreign('person_id')->references('id')->on('persons')->nullOnDelete();
            }

            if (Schema::hasTable('prepersons')) {
                $table->foreign('preperson_id')->references('id')->on('prepersons')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('compositions');
    }
};
