<?php

declare(strict_types=1);

use App\Enums\DetectedIssue\Status;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::create('detected_issues', static function (Blueprint $table) {
            $table->comment('Detected issue related to a medical device and recorded within an encounter.');

            $table->id();
            $table->uuid()->unique();

            $table->foreignId('person_id')
                ->nullable()
                ->constrained('persons');

            $table->foreignId('preperson_id')
                ->nullable()
                ->constrained('prepersons');

            $table->enum('status', Status::values());

            $table->foreignId('subject_id')
                ->constrained('identifiers');

            $table->foreignId('encounter_id')
                ->constrained('identifiers');

            $table->foreignId('author_id')
                ->nullable()
                ->constrained('identifiers');

            $table->foreignId('code_id')
                ->nullable()
                ->constrained('codeable_concepts');

            $table->text('detail')->nullable();

            $table->timestamp('identified_date_time')->nullable();

            $table->foreignId('implicated_id')
                ->nullable()
                ->constrained('identifiers');

            $table->foreignId('based_on_id')
                ->nullable()
                ->constrained('identifiers');

            $table->boolean('primary_source');

            $table->foreignId('report_origin_id')
                ->nullable()
                ->constrained('codeable_concepts');

            $table->foreignId('recorder_id')
                ->constrained('identifiers');

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('detected_issues');
    }
};