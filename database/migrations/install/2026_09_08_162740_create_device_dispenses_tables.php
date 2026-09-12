<?php

declare(strict_types=1);

use App\Enums\DeviceDispense\Status;
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
        Schema::create('device_dispenses', static function (Blueprint $table) {
            $table->comment('Medical device dispense recorded within an encounter.');
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('person_id')->nullable()->constrained('persons');
            $table->foreignId('preperson_id')->nullable()->constrained('prepersons');
            $table->foreignId('based_on_id')->nullable()->constrained('identifiers');
            $table->enum('status', Status::values());
            $table->foreignId('performer_id')->constrained('identifiers');
            $table->foreignId('location_id')->constrained('identifiers');
            $table->timestamp('when_handed_over');
            $table->text('note')->nullable();
            $table->foreignId('performer_legal_entity_id')->nullable()->constrained('identifiers');
            $table->foreignId('program_id')->nullable()->constrained('identifiers');
            $table->foreignId('part_of_id')->nullable()->constrained('identifiers');
            $table->foreignId('encounter_id')->constrained('identifiers');
            $table->uuid('context_episode_id')->nullable();
            $table->uuid('origin_episode_id')->nullable();
            $table->foreignId('status_reason_id')->nullable()->constrained('codeable_concepts');
            $table->string('explanatory_letter')->nullable();
            $table->timestamp('ehealth_inserted_at')->nullable();
            $table->timestamp('ehealth_updated_at')->nullable();
            $table->timestamps();
        });

        Schema::create('device_dispense_details', static function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_dispense_id')->constrained('device_dispenses')->cascadeOnDelete();
            $table->foreignId('device_id')->nullable()->constrained('identifiers');
            $table->foreignId('device_code_id')->nullable()->constrained('codeable_concepts');
            $table->foreignId('program_device_id')->nullable()->constrained('identifiers');
            $table->foreignId('quantity_id')->constrained('quantities');
            $table->decimal('sell_price', 12, 2)->nullable();
            $table->decimal('reimbursement_amount', 12, 2)->nullable();
            $table->decimal('discount_amount', 12, 2)->nullable();
            $table->timestamps();
        });

        Schema::create('device_dispense_supporting_info', static function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_dispense_id')->constrained('device_dispenses')->cascadeOnDelete();
            $table->foreignId('identifier_id')->constrained('identifiers')->cascadeOnDelete();
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
        Schema::dropIfExists('device_dispense_supporting_info');
        Schema::dropIfExists('device_dispense_details');
        Schema::dropIfExists('device_dispenses');
    }
};