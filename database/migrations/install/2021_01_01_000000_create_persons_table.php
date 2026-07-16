<?php

declare(strict_types=1);

use App\Enums\Person\Gender;
use App\Enums\Person\VerificationStatus;
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
        Schema::create('persons', static function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique()->nullable();
            $table->enum('verification_status', VerificationStatus::values())
                ->default(VerificationStatus::IN_REVIEW->value);
            $table->date('birth_date')->nullable();
            $table->string('birth_country')->nullable();
            $table->string('birth_settlement')->nullable();
            $table->enum('gender', Gender::values());
            $table->string('email')->unique()->nullable();
            $table->boolean('no_tax_id')->nullable();
            $table->string('tax_id')->nullable();
            $table->string('secret')->nullable();
            $table->string('unzr')->unique()->nullable();
            $table->jsonb('emergency_contact')->nullable();
            $table->boolean('patient_signed')->default(false)->comment("Person's evidence of sign the person request");
            $table->boolean('is_syncing')->default(false)->comment('Indicates whether the person data is currently being synchronized with an eHealth system');
            $table->boolean('process_disclosure_data_consent')->default(true)->comment("Person's evidence of information about consent to data disclosure");
            $table->date('death_date')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('persons');
    }
};
