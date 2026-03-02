<?php

declare(strict_types=1);

use App\Enums\JobStatus;
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
        Schema::create('confidant_persons', static function (Blueprint $table) {
            $table->id();
            $table->uuid();
            $table->foreignId('person_id')->comment('The person who is confidant')
                ->nullable()
                ->constrained('persons');
            $table->foreignId('subject_person_id')->comment('The person who need confidant')
                ->nullable()
                ->constrained('persons');
            $table->foreignId('person_request_id')->nullable()->constrained();
            $table->date('active_to')->nullable();
            $table->enum('sync_status', JobStatus::values())->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('confidant_persons');
    }
};
