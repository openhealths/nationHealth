<?php

declare(strict_types=1);

use App\Enums\Person\DiagnosticReportStatus;
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
        Schema::create('diagnostic_reports', static function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('person_id')->nullable()->constrained('persons');
            $table->foreignId('preperson_id')->nullable()->constrained('prepersons');
            $table->foreignId('based_on_id')->nullable()->constrained('identifiers');
            $table->enum('status', DiagnosticReportStatus::values());
            $table->foreignId('code_id')->constrained('identifiers');
            $table->timestamp('effective_date_time')->nullable();
            $table->timestamp('issued');
            $table->text('conclusion')->nullable();
            $table->foreignId('conclusion_code_id')->nullable()->constrained('codeable_concepts');
            $table->foreignId('recorded_by_id')->constrained('identifiers');
            $table->foreignId('encounter_id')->nullable()->constrained('identifiers');
            $table->boolean('primary_source');
            $table->foreignId('division_id')->nullable()->constrained('identifiers');
            $table->foreignId('managing_organization_id')->nullable()->constrained('identifiers');
            $table->foreignId('report_origin_id')->nullable()->constrained('codeable_concepts');
            $table->timestamps();
        });

        Schema::create('diagnostic_report_categories', static function (Blueprint $table) {
            $table->id();
            $table->foreignId('diagnostic_report_id')->constrained('diagnostic_reports')->cascadeOnDelete();
            $table->foreignId('codeable_concept_id')->constrained('codeable_concepts')->cascadeOnDelete();
            $table->timestamps();
        });

        Schema::create('diagnostic_report_performer', static function (Blueprint $table) {
            $table->id();
            $table->foreignId('diagnostic_report_id')->constrained('diagnostic_reports')->cascadeOnDelete();
            $table->foreignId('reference_id')->nullable()->constrained('identifiers')->cascadeOnDelete();
            $table->string('text')->nullable();
            $table->timestamps();
        });

        Schema::create('diagnostic_report_results_interpreter', static function (Blueprint $table) {
            $table->id();
            $table->foreignId('diagnostic_report_id')
                ->constrained('diagnostic_reports', 'id', 'fk_drri_diagnostic_report_id')
                ->cascadeOnDelete();
            $table->foreignId('reference_id')->nullable()->constrained('identifiers')->cascadeOnDelete();
            $table->string('text')->nullable();
            $table->timestamps();
        });

        Schema::create('diagnostic_report_specimens', static function (Blueprint $table) {
            $table->id();
            $table->foreignId('diagnostic_report_id')->constrained()->cascadeOnDelete();
            $table->foreignId('identifier_id')->constrained('identifiers')->cascadeOnDelete();
            $table->timestamps();
        });

        Schema::create('diagnostic_report_used_references', static function (Blueprint $table) {
            $table->id();
            $table->foreignId('diagnostic_report_id')->constrained()->cascadeOnDelete();
            $table->foreignId('identifier_id')->constrained('identifiers')->cascadeOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('diagnostic_report_specimens');

        Schema::dropIfExists('diagnostic_report_used_references');

        Schema::dropIfExists('diagnostic_report_results_interpreter');

        Schema::dropIfExists('diagnostic_report_performer');

        Schema::dropIfExists('diagnostic_report_categories');

        Schema::dropIfExists('diagnostic_reports');
    }
};
