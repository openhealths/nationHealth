<?php

declare(strict_types=1);

use App\Enums\Person\MergedPersonStatus;
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
        Schema::create('merged_persons', static function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique()->comment('eHealth identifier of the merged person record');
            $table->foreignId('person_id')
                ->comment('Identified patient the person was merged into')
                ->constrained('persons');
            $table->foreignId('merge_person_id')
                ->comment('Preperson that was merged into the identified patient')
                ->constrained('prepersons');
            $table->enum('status', MergedPersonStatus::values());
            $table->dateTime('ehealth_inserted_at');
            $table->dateTime('ehealth_updated_at');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('merged_persons');
    }
};
