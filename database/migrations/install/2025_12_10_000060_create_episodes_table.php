<?php

declare(strict_types=1);

use App\Enums\Person\EpisodeStatus;
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
        Schema::create('episodes', static function (Blueprint $table) {
            $table->id();
            $table->uuid();
            $table->foreignId('person_id')->constrained('persons');
            $table->foreignId('encounter_id')->nullable()->constrained('encounters');
            $table->foreignId('episode_type_id')->nullable()->constrained('codings');
            $table->enum('status', EpisodeStatus::values());
            $table->string('name');
            $table->foreignId('managing_organization_id')->nullable()->constrained('identifiers');
            $table->foreignId('care_manager_id')->nullable()->constrained('identifiers');
            $table->timestamp('ehealth_inserted_at')->nullable();
            $table->timestamp('ehealth_updated_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('episodes');
    }
};
