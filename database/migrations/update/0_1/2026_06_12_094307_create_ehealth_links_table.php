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
        if (!Schema::hasTable('ehealth_links')) {
            Schema::create('ehealth_links', function (Blueprint $table) {
                $table->id();

                $table->morphs('linkable');

                $table->foreignId('ehealth_job_id')->nullable()->constrained('ehealth_jobs')->nullOnDelete();

                $table->string('entity')->nullable();

                $table->text('href')->nullable();

                $table->timestamps();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ehealth_links');
    }
};
