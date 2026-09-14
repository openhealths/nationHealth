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
            $table->comment('Record of medical devices handed over to the patient, within an encounter.');
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('person_id')->nullable()->constrained('persons');
            $table->foreignId('preperson_id')->nullable()->constrained('prepersons');
            $table->enum('status', Status::values())->default(Status::PROCESSED->value);
            $table->foreignId('based_on_id')
                ->nullable()
                ->constrained('identifiers')
                ->comment('Device request the dispense is issued against');
            $table->foreignId('part_of_id')
                ->nullable()
                ->constrained('identifiers')
                ->comment('Procedure the dispense is part of');
            $table->foreignId('performer_id')->constrained('identifiers');
            $table->foreignId('location_id')
                ->constrained('identifiers')
                ->comment('Division the devices were handed over in');
            $table->timestamp('when_handed_over');
            $table->unsignedInteger('quantity');
            $table->foreignId('device_code_id')
                ->nullable()
                ->constrained('codeable_concepts')
                ->comment('Classification type of the device, when the dispense names a type');
            $table->foreignId('device_definition_id')
                ->nullable()
                ->constrained('identifiers')
                ->comment('Device definition, when the dispense names a model or a brand');
            $table->foreignId('context_id')->constrained('identifiers');
            $table->string('explanatory_letter')
                ->nullable()
                ->comment('Reason the dispense was marked as entered in error');
            $table->timestamp('ehealth_inserted_at')->nullable();
            $table->timestamp('ehealth_updated_at')->nullable();
            $table->timestamps();
        });

        Schema::create('device_dispense_supporting_info', static function (Blueprint $table) {
            $table->comment('Records a device dispense points at as supporting information.');
            $table->id();
            $table->foreignId('device_dispense_id')->constrained('device_dispenses')->cascadeOnDelete();
            $table->foreignId('identifier_id')->constrained('identifiers');
            $table->timestamps();

            $table->unique(['device_dispense_id', 'identifier_id']);
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
        Schema::dropIfExists('device_dispenses');
    }
};
