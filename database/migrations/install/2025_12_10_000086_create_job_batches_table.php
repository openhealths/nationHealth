<?php

declare(strict_types=1);

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
        Schema::create('job_batches', function (Blueprint $table) {
            $table->string('id')->primary();

            // Add foreign key constraint to legal_entities table
            $table->foreignId('legal_entity_id')->nullable()->constrained()->nullOnDelete();

            // Add legal_entity_id field to track which legal entity the batch belongs to
            // $table->unsignedBigInteger('legal_entity_id')->nullable()->after('options');

            // Add foreign key constraint to legal_entities table
            // $table->foreign('legal_entity_id')
            //       ->references('id')
            //       ->on('legal_entities')
            //       ->onDelete('set null'); // Set to null if legal entity is deleted

            $table->string('name');
            $table->integer('total_jobs');
            $table->integer('pending_jobs');
            $table->integer('failed_jobs');
            $table->longText('failed_job_ids');
            $table->mediumText('options')->nullable();
            $table->integer('cancelled_at')->nullable();
            $table->integer('created_at');
            $table->integer('finished_at')->nullable();

            // Add index for better query performance
            $table->index(['legal_entity_id', 'created_at'], 'idx_job_batches_legal_entity_created');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('job_batches', function (Blueprint $table) {
            // Drop foreign key constraint first
            $table->dropForeign(['legal_entity_id']);

            // Drop index
            $table->dropIndex('idx_job_batches_legal_entity_created');
        });

        Schema::dropIfExists('job_batches');
    }
};
