<?php

use App\Enums\JobStatus;
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
        Schema::create('ehealth_links', function (Blueprint $table) {
            $table->id();

            $table->morphs('linkable');

            $table->foreignId('ehealth_job_id')->nullable()->constrained('ehealth_jobs')->nullOnDelete();

            $table->string('entity')->nullable();

            $table->text('href')->nullable();

            $table->json('error')->nullable()->comment('Error details if the link creation or processing failed');
            $table->string('error_code')->nullable()->comment('Error code if the link creation or processing failed');
            $table->enum('status', JobStatus::values())->default(JobStatus::PENDING)->nullable()->comment('Status of the link processing, e.g., pending, processed, failed');

            $table->timestamps();

            $table->unique(['linkable_type', 'linkable_id', 'ehealth_job_id'], 'ehealth_links_linkable_job_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ehealth_links');
    }
};
