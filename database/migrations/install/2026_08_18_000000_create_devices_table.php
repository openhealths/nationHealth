<?php

declare(strict_types=1);

use App\Enums\Device\Status;
use App\Enums\Equipment\Type as DeviceNameType;
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
        Schema::create('devices', static function (Blueprint $table) {
            $table->comment('Medical device associated with the encounter it was recorded in.');
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('person_id')->nullable()->constrained('persons');
            $table->foreignId('preperson_id')->nullable()->constrained('prepersons');
            $table->enum('status', Status::values());
            $table->foreignId('type_id')->constrained('codeable_concepts');
            $table->string('model_number')->nullable();
            $table->string('lot_number')->nullable();
            $table->string('manufacturer')->nullable();
            $table->string('serial_number')->nullable();
            $table->timestamp('manufacture_date')->nullable();
            $table->timestamp('expiration_date')->nullable();
            $table->text('note')->nullable();
            $table->boolean('primary_source');
            $table->foreignId('report_origin_id')->nullable()->constrained('codeable_concepts');
            $table->foreignId('context_id')->constrained('identifiers');
            $table->foreignId('recorder_id')->constrained('identifiers');
            $table->foreignId('definition_id')->nullable()->constrained('identifiers');
            $table->foreignId('parent_id')->nullable()->constrained('identifiers');
            $table->timestamps();
        });

        Schema::create('device_names', static function (Blueprint $table) {
            $table->comment('Names the device is known by, one per name type.');
            $table->id();
            $table->foreignId('device_id')->constrained()->cascadeOnDelete();
            $table->enum('type', DeviceNameType::values());
            $table->string('value');
            $table->timestamps();
        });

        Schema::create('device_properties', static function (Blueprint $table) {
            $table->comment('Properties of the device, each carrying exactly one value.');
            $table->id();
            $table->foreignId('device_id')->constrained()->cascadeOnDelete();
            $table->foreignId('code_id')->constrained('codeable_concepts');
            $table->timestamps();
        });

        // The values table is created before device_properties exists, so the owner column is constrained here
        Schema::table('values', static function (Blueprint $table) {
            $table->foreignId('device_property_id')
                ->nullable()
                ->after('observation_component_id')
                ->constrained('device_properties')
                ->cascadeOnDelete();
        });

        Schema::create('device_identifiers', static function (Blueprint $table) {
            $table->comment('Identifiers the device is known by in external systems.');
            $table->id();
            $table->foreignId('device_id')->constrained()->cascadeOnDelete();
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
        Schema::dropIfExists('device_identifiers');

        Schema::table('values', static function (Blueprint $table) {
            $table->dropConstrainedForeignId('device_property_id');
        });

        Schema::dropIfExists('device_properties');

        Schema::dropIfExists('device_names');

        Schema::dropIfExists('devices');
    }
};
