<?php

declare(strict_types=1);

use App\Core\ExtendedMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends ExtendedMigration
{
    /**
     * Compositions table stores Медичні висновки (МВН/МВТН) from the eHealth API.
     *
     * Composition data is fetched from eHealth and persisted locally so that:
     *  - lists can be rendered without hitting the API on every page load;
     *  - status changes (sign / cancel) are reflected immediately in the UI.
     *
     * The `data` JSON column holds the full API response for display purposes,
     * while indexed scalar columns enable efficient filtering.
     */
    public function up(): void
    {
        Schema::create('compositions', function (Blueprint $table) {
            $table->id();

            $table->uuid('uuid')->unique()->comment('eHealth composition UUID');

            // Patient references — one of these is always set
            $table->unsignedBigInteger('person_id')->nullable()->index();
            $table->unsignedBigInteger('preperson_id')->nullable()->index();

            // Core composition fields (indexed for search/filter)
            $table->string('type')->index()->comment('COMPOSITION_TYPES: NEWBORN | TEMP_DISABILITY');
            $table->string('category')->nullable()->comment('COMPOSITION_CATEGORIES');
            $table->string('status')
                ->default('PRELIMINARY')
                ->index()
                ->comment('COMPOSITION_STATUS: PRELIMINARY | FINAL | AMENDED | ENTERED_IN_ERROR');
            $table->string('title')->nullable()->comment('Composition number / title from eHealth');

            // Encounter & episode references (UUIDs as strings — foreign keys are in eHealth, not local DB)
            $table->string('encounter_uuid')->nullable()->index();
            $table->string('episode_of_care_uuid')->nullable()->index();

            // Author (employee) & custodian (legal entity) — eHealth UUIDs
            $table->string('author_uuid')->nullable()->comment('eHealth employee UUID');
            $table->string('custodian_uuid')->nullable()->comment('eHealth legal entity UUID');

            // Focus and subject are both searchable in their own right: searchComposition
            // accepts either one, and they differ for a birth conclusion, where the subject
            // is the newborn and the focus is the woman who gave birth.
            $table->string('section_focus_uuid')->nullable()->index()->comment('eHealth person UUID of section.focus');
            $table->string('subject_uuid')->nullable()->index()->comment('eHealth preperson/person UUID of composition subject');

            // Event period (validity period of the composition)
            $table->timestampTz('event_period_start')->nullable();
            $table->timestampTz('event_period_end')->nullable();

            // Composition creation date from eHealth
            $table->timestampTz('composition_date')->nullable()->comment('eHealth field: date');

            // Extended fields for МВТН (TEMP_DISABILITY)
            $table->string('inform_with_uuid')->nullable()->comment('Authentication method UUID chosen by user');
            $table->boolean('is_accident')->nullable()->comment('МВТН: industrial accident');
            $table->boolean('is_intoxicated')->nullable()->comment('МВТН: alcohol/drug intoxication');
            $table->boolean('is_foreign_treatment')->nullable()->comment('МВТН: disability started abroad');
            $table->boolean('is_force_renew')->nullable()->comment('МВТН: force new disability case');
            $table->string('treatment_violation')->nullable()->comment('МВТН: treatment regime violation code');
            $table->date('treatment_violation_date')->nullable()->comment('МВТН: date of treatment violation');

            // Newborn-specific extensions (МВН)
            $table->date('newborn_birth_date')->nullable();
            $table->string('newborn_sex')->nullable();

            // relatesTo — link to a previous conclusion in the same chain
            $table->string('relates_to_code')
                ->nullable()
                ->comment('COMPOSITION_RELATION_CODE: absent | appends | replaces | transforms');
            $table->string('relates_to_target_uuid')->nullable()->comment('UUID of the previous composition');

            // Async job tracking
            $table->string('async_job_id')->nullable()->comment('eHealth asyncJobId returned at creation');
            $table->string('async_job_status')->nullable()->comment('PENDING | DONE | FAILED');

            // ERLN integration status (МВТН only)
            $table->string('erln_status')->nullable()->comment('COMPOSITION_PROCESSING_STATUS from integrationData');
            $table->string('erln_record_number')->nullable()->comment('ERLN record number on success');
            $table->text('erln_status_message')->nullable()->comment('Error message from ERLN');

            // Full raw API response for display
            $table->json('data')->nullable()->comment('Full eHealth API response payload');

            // Timestamps
            $table->timestampTz('ehealth_inserted_at')->nullable();
            $table->timestampTz('ehealth_updated_at')->nullable();
            $table->timestamps();

            // Foreign keys to local tables
            $table->foreign('person_id')->references('id')->on('persons')->nullOnDelete();
            $table->foreign('preperson_id')->references('id')->on('prepersons')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('compositions');
    }
};
