<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Local cache of eHealth Composition (МВН / МВТН), stored in the same FHIR
     * shape as the other medical-event tables: CodeableConcept and Identifier FKs,
     * a morph Period for event.period, and the original extension list as JSON.
     */
    public function up(): void
    {
        Schema::create('compositions', static function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();

            $table->foreignId('person_id')->nullable()->constrained('persons')->nullOnDelete();
            $table->foreignId('preperson_id')->nullable()->constrained('prepersons')->nullOnDelete();

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

            $table->json('extension')->nullable();
            $table->json('data')->nullable();

            $table->string('async_job_id')->nullable();
            $table->string('async_job_status')->nullable();

            $table->string('erln_status')->nullable();
            $table->string('erln_record_number')->nullable();
            $table->text('erln_status_message')->nullable();

            $table->timestampTz('ehealth_inserted_at')->nullable();
            $table->timestampTz('ehealth_updated_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('compositions');
    }
};
