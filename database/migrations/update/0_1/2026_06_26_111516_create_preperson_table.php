<?php

declare(strict_types=1);

use App\Enums\Person\Gender;
use App\Enums\Preperson\Status;
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
        Schema::create('prepersons', static function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique()->nullable()->comment('MPI identifier of the preperson');
            $table->string('external_id')->nullable()->unique()->comment('Identifier from external system');
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('second_name')->nullable();
            $table->enum('gender', Gender::values());
            $table->date('birth_date')->nullable();
            $table->jsonb('emergency_contact')->nullable();
            $table->date('death_date')->nullable();
            $table->text('note')->nullable();
            $table->enum('status', Status::values())->nullable();
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
        Schema::dropIfExists('prepersons');
    }
};
