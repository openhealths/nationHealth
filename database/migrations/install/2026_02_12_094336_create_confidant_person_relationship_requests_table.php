<?php

declare(strict_types=1);

use App\Enums\Person\ConfidantPersonRelationshipRequestStatus;
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
        Schema::create('confidant_person_relationship_requests', static function (Blueprint $table) {
            $table->id();
            $table->uuid();
            $table->foreignId('person_id')->constrained('persons');
            $table->string('action')->nullable()
                ->comment('Left empty for the requests read from the listing, which does not carry it');
            $table->enum('status', ConfidantPersonRelationshipRequestStatus::values());
            $table->string('channel');
            $table->jsonb('documents')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('confidant_person_relationship_requests');
    }
};
