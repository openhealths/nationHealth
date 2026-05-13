<?php

declare(strict_types=1);

use App\Enums\Person\ProcedureStatus;
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
        Schema::create('procedures', static function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('person_id')->constrained('persons');
            $table->enum('status', ProcedureStatus::values());
            $table->foreignId('based_on_id')->nullable()->constrained('identifiers');
            $table->foreignId('code_id')->constrained('identifiers');
            $table->foreignId('encounter_id')->nullable()->constrained('identifiers');
            $table->foreignId('recorded_by_id')->constrained('identifiers');
            $table->boolean('primary_source');
            $table->foreignId('performer_id')->nullable()->constrained('identifiers');
            $table->foreignId('report_origin_id')->nullable()->constrained('codeable_concepts');
            $table->foreignId('division_id')->nullable()->constrained('identifiers');
            $table->foreignId('managing_organization_id')->nullable()->constrained('identifiers');
            $table->foreignId('outcome_id')->nullable()->constrained('codeable_concepts');
            $table->text('note')->nullable()->comment('Any other notes and comments about the procedure.');
            $table->foreignId('category_id')->nullable()->constrained('codeable_concepts');
            $table->timestamps();
        });

        Schema::create('procedure_reason_references', static function (Blueprint $table) {
            $table->comment('The justification of why the procedure was performed.');
            $table->id();
            $table->foreignId('procedure_id')->constrained()->cascadeOnDelete();
            $table->foreignId('identifier_id')->constrained('identifiers')->cascadeOnDelete();
            $table->timestamps();
        });

        Schema::create('procedure_complication_details', static function (Blueprint $table) {
            $table->comment('Any complications that occurred during the procedure, or in the immediate post-performance period. Could be filled only for procedure in encounter package and only with reference to condition from same encounter');
            $table->id();
            $table->foreignId('procedure_id')->constrained()->cascadeOnDelete();
            $table->foreignId('identifier_id')->constrained('identifiers')->cascadeOnDelete();
            $table->timestamps();
        });

        Schema::create('procedure_used_codes', static function (Blueprint $table) {
            $table->id();
            $table->foreignId('procedure_id')->constrained()->cascadeOnDelete();
            $table->foreignId('codeable_concept_id')->constrained('codeable_concepts')->cascadeOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('procedure_used_codes');

        Schema::dropIfExists('procedure_complication_details');

        Schema::dropIfExists('procedure_reason_references');

        Schema::dropIfExists('procedures');
    }
};
