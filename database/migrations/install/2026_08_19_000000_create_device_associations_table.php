<?php

declare(strict_types=1);

use App\Enums\DeviceAssociation\Status;
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
        Schema::create('device_associations', static function (Blueprint $table) {
            $table->comment('Association of a medical device with the patient, recorded within an encounter.');
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('person_id')->nullable()->constrained('persons');
            $table->foreignId('preperson_id')->nullable()->constrained('prepersons');
            $table->foreignId('device_id')->constrained('identifiers');
            $table->enum('status', Status::values());
            $table->foreignId('body_site_id')->nullable()->constrained('codeable_concepts');
            $table->date('association_date')->nullable();
            $table->timestamp('recorded');
            $table->boolean('primary_source');
            $table->foreignId('report_origin_id')->nullable()->constrained('codeable_concepts');
            $table->foreignId('context_id')->constrained('identifiers');
            $table->foreignId('recorder_id')->constrained('identifiers');
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
        Schema::dropIfExists('device_associations');
    }
};
