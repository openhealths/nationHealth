<?php

declare(strict_types=1);

use App\Enums\License\Type;
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
        Schema::create('licenses', static function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique()->nullable();
            $table->foreignId('legal_entity_id')->constrained()->cascadeOnDelete();
            $table->enum('type', Type::values());
            $table->boolean('is_active')->nullable();
            $table->string('issued_by');
            $table->date('issued_date');
            $table->string('issuer_status')->nullable();
            $table->date('active_from_date');
            $table->string('order_no');
            $table->string('license_number')->nullable();
            $table->date('expiry_date')->nullable();
            $table->string('what_licensed')->nullable();
            $table->boolean('is_primary')->default(false);
            $table->date('ehealth_inserted_at')->nullable();
            $table->uuid('ehealth_inserted_by')->nullable();
            $table->date('ehealth_updated_at')->nullable();
            $table->uuid('ehealth_updated_by')->nullable();
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
        Schema::dropIfExists('licenses');
    }
};
