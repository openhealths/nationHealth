<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Move `compositions` from the FHIR-aligned shape of an earlier #644 experiment
 * (type_id / encounter_id / …) to the scalar string columns the application expects
 * (type / encounter_uuid / …).
 *
 * An earlier revision of this migration dropped and recreated the table. That was safe
 * only against the one local database it was written for; anywhere the table already
 * holds conclusions it destroys them, and a medical conclusion cannot be re-derived from
 * anything the MIS keeps. So the change is applied forward in place: add what is
 * missing, copy the values across, then remove what has been superseded.
 */
return new class extends Migration
{
    /**
     * Legacy column => its replacement.
     */
    private const array RENAMED = [
        'type_id' => 'type',
        'category_id' => 'category',
        'status_id' => 'status',
        'encounter_id' => 'encounter_uuid',
        'episode_of_care_id' => 'episode_of_care_uuid',
        'author_id' => 'author_uuid',
        'custodian_id' => 'custodian_uuid',
        'section_focus_id' => 'section_focus_uuid',
        'subject_id' => 'subject_uuid',
        'inform_with_id' => 'inform_with_uuid',
        'relates_to_target_id' => 'relates_to_target_uuid',
    ];

    public function up(): void
    {
        if (!Schema::hasTable('compositions')) {
            $this->createTable();

            return;
        }

        $this->addMissingColumns();
        $this->copyLegacyValues();
        $this->dropSupersededColumns();
    }

    /**
     * Intentionally irreversible.
     *
     * The legacy shape cannot hold everything the current one does, so rolling back
     * would mean deciding which conclusion data to discard. Rolling forward from a
     * restored dump is the supported path.
     */
    public function down(): void
    {
    }

    /**
     * Add every column the application expects but the legacy table lacks.
     */
    private function addMissingColumns(): void
    {
        $columns = [
            'type' => static fn (Blueprint $table) => $table->string('type')
                ->nullable()
                ->comment('COMPOSITION_TYPES: NEWBORN | TEMP_DISABILITY'),
            'category' => static fn (Blueprint $table) => $table->string('category')
                ->nullable()
                ->comment('COMPOSITION_CATEGORIES'),
            'status' => static fn (Blueprint $table) => $table->string('status')
                ->nullable()
                ->comment('COMPOSITION_STATUS'),
            'title' => static fn (Blueprint $table) => $table->string('title')->nullable(),
            'encounter_uuid' => static fn (Blueprint $table) => $table->string('encounter_uuid')->nullable(),
            'episode_of_care_uuid' => static fn (Blueprint $table) => $table->string('episode_of_care_uuid')
                ->nullable(),
            'author_uuid' => static fn (Blueprint $table) => $table->string('author_uuid')->nullable(),
            'custodian_uuid' => static fn (Blueprint $table) => $table->string('custodian_uuid')->nullable(),
            'section_focus_uuid' => static fn (Blueprint $table) => $table->string('section_focus_uuid')->nullable(),
            'subject_uuid' => static fn (Blueprint $table) => $table->string('subject_uuid')->nullable(),
            'event_period_start' => static fn (Blueprint $table) => $table->timestampTz('event_period_start')
                ->nullable(),
            'event_period_end' => static fn (Blueprint $table) => $table->timestampTz('event_period_end')->nullable(),
            'composition_date' => static fn (Blueprint $table) => $table->timestampTz('composition_date')->nullable(),
            'inform_with_uuid' => static fn (Blueprint $table) => $table->string('inform_with_uuid')->nullable(),
            'is_accident' => static fn (Blueprint $table) => $table->boolean('is_accident')->nullable(),
            'is_intoxicated' => static fn (Blueprint $table) => $table->boolean('is_intoxicated')->nullable(),
            'is_foreign_treatment' => static fn (Blueprint $table) => $table->boolean('is_foreign_treatment')
                ->nullable(),
            'is_force_renew' => static fn (Blueprint $table) => $table->boolean('is_force_renew')->nullable(),
            'treatment_violation' => static fn (Blueprint $table) => $table->string('treatment_violation')->nullable(),
            'treatment_violation_date' => static fn (Blueprint $table) => $table->date('treatment_violation_date')
                ->nullable(),
            'newborn_birth_date' => static fn (Blueprint $table) => $table->date('newborn_birth_date')->nullable(),
            'newborn_sex' => static fn (Blueprint $table) => $table->string('newborn_sex')->nullable(),
            'relates_to_code' => static fn (Blueprint $table) => $table->string('relates_to_code')->nullable(),
            'relates_to_target_uuid' => static fn (Blueprint $table) => $table->string('relates_to_target_uuid')
                ->nullable(),
            'async_job_id' => static fn (Blueprint $table) => $table->string('async_job_id')->nullable(),
            'async_job_status' => static fn (Blueprint $table) => $table->string('async_job_status')->nullable(),
            'erln_status' => static fn (Blueprint $table) => $table->string('erln_status')->nullable(),
            'erln_record_number' => static fn (Blueprint $table) => $table->string('erln_record_number')->nullable(),
            'erln_status_message' => static fn (Blueprint $table) => $table->text('erln_status_message')->nullable(),
            'data' => static fn (Blueprint $table) => $table->json('data')->nullable(),
            'ehealth_inserted_at' => static fn (Blueprint $table) => $table->timestampTz('ehealth_inserted_at')
                ->nullable(),
            'ehealth_updated_at' => static fn (Blueprint $table) => $table->timestampTz('ehealth_updated_at')
                ->nullable(),
        ];

        $missing = array_filter(
            $columns,
            static fn (string $name): bool => !Schema::hasColumn('compositions', $name),
            ARRAY_FILTER_USE_KEY
        );

        if ($missing === []) {
            return;
        }

        Schema::table('compositions', function (Blueprint $table) use ($missing): void {
            foreach ($missing as $definition) {
                $definition($table);
            }
        });
    }

    /**
     * Carry values over from every legacy column that still exists.
     */
    private function copyLegacyValues(): void
    {
        foreach (self::RENAMED as $legacy => $current) {
            if (!Schema::hasColumn('compositions', $legacy) || !Schema::hasColumn('compositions', $current)) {
                continue;
            }

            DB::table('compositions')
                ->whereNull($current)
                ->whereNotNull($legacy)
                ->update([$current => DB::raw(sprintf('%s::text', $legacy))]);
        }
    }

    /**
     * Drop the legacy columns once their values are safely in the new ones.
     */
    private function dropSupersededColumns(): void
    {
        $present = array_values(array_filter(
            array_keys(self::RENAMED),
            static fn (string $legacy): bool => Schema::hasColumn('compositions', $legacy)
        ));

        if ($present === []) {
            return;
        }

        Schema::table('compositions', static function (Blueprint $table) use ($present): void {
            $table->dropColumn($present);
        });
    }

    /**
     * Create the table for an environment that never had it.
     */
    private function createTable(): void
    {
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
            $table->string('subject_uuid')->nullable()->index()->comment('eHealth preperson/person UUID of subject');

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
};
