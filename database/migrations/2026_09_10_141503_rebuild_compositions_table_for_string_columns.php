<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Local mis_dev still had the FHIR-aligned compositions shape (type_id / encounter_id / …)
 * from an earlier #644 experiment, while the app code on this branch expects scalar
 * string columns (type, encounter_uuid, …). The table is empty in local, so rebuild it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('compositions');

        Schema::create('compositions', function (Blueprint $table) {
            $table->id();

            $table->uuid('uuid')->unique()->comment('eHealth composition UUID');

            $table->unsignedBigInteger('person_id')->nullable()->index();
            $table->unsignedBigInteger('preperson_id')->nullable()->index();

            $table->string('type')->index()->comment('COMPOSITION_TYPES: NEWBORN | TEMP_DISABILITY');
            $table->string('category')->nullable()->comment('COMPOSITION_CATEGORIES');
            $table->string('status')
                ->default('PRELIMINARY')
                ->index()
                ->comment('COMPOSITION_STATUS: PRELIMINARY | FINAL | AMENDED | ENTERED_IN_ERROR');
            $table->string('title')->nullable()->comment('Composition number / title from eHealth');

            $table->string('encounter_uuid')->nullable()->index();
            $table->string('episode_of_care_uuid')->nullable()->index();

            $table->string('author_uuid')->nullable()->comment('eHealth employee UUID');
            $table->string('custodian_uuid')->nullable()->comment('eHealth legal entity UUID');

            $table->string('section_focus_uuid')->nullable()->index()->comment('eHealth person UUID of section.focus');
            $table->string('subject_uuid')->nullable()->index()->comment('eHealth preperson/person UUID of composition subject');

            $table->timestampTz('event_period_start')->nullable();
            $table->timestampTz('event_period_end')->nullable();

            $table->timestampTz('composition_date')->nullable()->comment('eHealth field: date');

            $table->string('inform_with_uuid')->nullable()->comment('Authentication method UUID chosen by user');
            $table->boolean('is_accident')->nullable()->comment('МВТН: industrial accident');
            $table->boolean('is_intoxicated')->nullable()->comment('МВТН: alcohol/drug intoxication');
            $table->boolean('is_foreign_treatment')->nullable()->comment('МВТН: disability started abroad');
            $table->boolean('is_force_renew')->nullable()->comment('МВТН: force new disability case');
            $table->string('treatment_violation')->nullable()->comment('МВТН: treatment regime violation code');
            $table->date('treatment_violation_date')->nullable()->comment('МВТН: date of treatment violation');

            $table->date('newborn_birth_date')->nullable();
            $table->string('newborn_sex')->nullable();

            $table->string('relates_to_code')
                ->nullable()
                ->comment('COMPOSITION_RELATION_CODE: absent | appends | replaces | transforms');
            $table->string('relates_to_target_uuid')->nullable()->comment('UUID of the previous composition');

            $table->string('async_job_id')->nullable()->comment('eHealth asyncJobId returned at creation');
            $table->string('async_job_status')->nullable()->comment('PENDING | DONE | FAILED');

            $table->string('erln_status')->nullable()->comment('COMPOSITION_PROCESSING_STATUS from integrationData');
            $table->string('erln_record_number')->nullable()->comment('ERLN record number on success');
            $table->text('erln_status_message')->nullable()->comment('Error message from ERLN');

            $table->json('data')->nullable()->comment('Full eHealth API response payload');

            $table->timestampTz('ehealth_inserted_at')->nullable();
            $table->timestampTz('ehealth_updated_at')->nullable();
            $table->timestamps();

            $table->foreign('person_id')->references('id')->on('persons')->nullOnDelete();
            $table->foreign('preperson_id')->references('id')->on('prepersons')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('compositions');
    }
};
