<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('composition_operations')) {
            Schema::create('composition_operations', static function (Blueprint $table): void {
                $table->id();
                $table->foreignId('composition_id')->nullable()->index()->constrained('compositions')->nullOnDelete();
                $table->foreignId('ehealth_job_id')->constrained('ehealth_jobs');
                $table->string('remote_job_id')->unique();
                $table->string('operation');
                $table->string('composition_type')->nullable();
                $table->foreignId('person_id')->nullable()->index()->constrained('persons')->nullOnDelete();
                $table->foreignId('preperson_id')->nullable()->index()->constrained('prepersons')->nullOnDelete();
                $table->uuid('encounter_uuid')->nullable();
                $table->uuid('episode_uuid')->nullable();
                $table->uuid('author_uuid')->nullable();
                $table->timestamps();
            });
        }
        if (!Schema::hasTable('composition_integrations')) {
            Schema::create('composition_integrations', static function (Blueprint $table): void {
                $table->id();
                $table->foreignId('composition_id')->index()->constrained('compositions')->cascadeOnDelete();
                $table->string('component');
                $table->string('type');
                $table->string('integration_status')->nullable();
                $table->string('task_status')->nullable();
                $table->string('record_number')->nullable();
                $table->text('status_message')->nullable();
                $table->timestampTz('ehealth_updated_at')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('composition_integrations');
        Schema::dropIfExists('composition_operations');
    }
};
