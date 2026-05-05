<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;
use App\Enums\LegalEntity\ReorganizationTypes;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('legators', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->comment('merged_from legal entity id');
            $table->string('edrpou')->comment('Unified Register of Businesses and Organizations');
            $table->string('name')->comment('full official name of legal entity');
            $table->boolean('is_active')->default(true)->comment('whether relationship between legal entities is active');
            $table->enum('type', array_column(ReorganizationTypes::cases(), 'value'))->comment('type of legal entities relationship');
            $table->string('reason')->comment('the legit documents which prove the relationship between legal entities');
            $table->date('reason_date')->nullable()->comment('the legit documents date');
            $table->foreignId('legal_entity_id')->constrained('legal_entities')->cascadeOnDelete()->cascadeOnUpdate();
            $table->date('ehealth_inserted_at');
            $table->uuid('inserted_by');
            $table->timestamps();

            $table->unique(['uuid', 'legal_entity_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('legators');
    }
};
