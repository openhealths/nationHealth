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
     *
     * Also used by `artisan update` (see database/migrations/update/0_1/) so
     * existing environments get the table without a fresh install.
     */
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

            $table->json('extension')->nullable();
            $table->json('data')->nullable();

            $table->string('async_job_id')->nullable();
            $table->string('async_job_status')->nullable();
            $table->string('async_job_operation')->nullable()
                ->comment('CREATE | SIGN | CANCEL | ERLN_RETRY');
            $table->text('async_job_error')->nullable();

            $table->string('erln_status')->nullable();
            $table->string('erln_record_number')->nullable();
            $table->text('erln_status_message')->nullable();

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
