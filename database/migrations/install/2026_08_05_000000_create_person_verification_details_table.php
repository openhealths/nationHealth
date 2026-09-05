<?php

declare(strict_types=1);

use App\Enums\Person\VerificationSource;
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
        Schema::create('person_verification_details', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('person_id')->constrained('persons')->cascadeOnDelete();
            $table->enum('source', VerificationSource::values())
                ->comment('Registry the person data was verified against');
            $table->enum('verification_status', VerificationStatus::values());
            $table->string('verification_reason');
            $table->text('verification_comment')->nullable();
            $table->string('result')->nullable()->comment('DRFO verification result code');
            $table->string('status')->nullable()->comment('EIS MVS document status code');
            $table->timestamps();

            $table->unique(['person_id', 'source']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('person_verification_details');
    }
};
