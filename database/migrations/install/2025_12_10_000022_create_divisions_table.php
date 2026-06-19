<?php

declare(strict_types=1);

use App\Enums\Status;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected const array VALID_STATUSES = [
        'ACTIVE',
        'INACTIVE',
        'DRAFT',
        'UNSYNCED'
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('divisions', static function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique()->nullable();
            $table->string('external_id')->nullable();
            $table->string('name');
            $table->string('type')->nullable();
            $table->boolean('mountain_group');
            $table->jsonb('location')->nullable();
            $table->string('email');
            $table->jsonb('working_hours')->nullable();
            $table->boolean('is_active')->default(false);
            $table->foreignId('legal_entity_id')->constrained('legal_entities')->cascadeOnDelete()->cascadeOnUpdate();
            $table->enum('status', Status::only(self::VALID_STATUSES))->nullable();
            $table->string('dls_id')->nullable();
            $table->boolean('dls_verified')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('divisions');
    }
};
