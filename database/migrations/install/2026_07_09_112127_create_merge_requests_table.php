<?php

declare(strict_types=1);

use App\Enums\MergeRequest\Status;
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
        Schema::create('merge_requests', static function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique()->comment('eHealth identifier of the merge request');
            $table->foreignId('master_person_id')
                ->nullable()
                ->comment('Identified patient the preperson is merged into')
                ->constrained('persons');
            $table->foreignId('merge_person_id')
                ->comment('Preperson being merged')
                ->constrained('prepersons');
            $table->enum('status', Status::values());
            $table->json('data_to_be_signed')->nullable();
            $table->dateTime('ehealth_inserted_at')->nullable();
            $table->uuid('ehealth_inserted_by')->nullable();
            $table->dateTime('ehealth_updated_at')->nullable();
            $table->uuid('ehealth_updated_by')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('merge_requests');
    }
};
