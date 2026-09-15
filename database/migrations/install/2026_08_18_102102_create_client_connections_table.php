<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use App\Enums\LegalEntity\ConnectionStatus;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('client_connections', function (Blueprint $table) {
            $table->id();
            $table->uuid()->comment('Connection UUID at the eHealth side');
            $table->foreignUuid('client_uuid')->nullable()->comment('Client UUID at the eHealth side')->constrained('clients', 'uuid')->cascadeOnDelete();
            $table->foreignId('legal_entity_id')->constrained('legal_entities')->cascadeOnDelete();
            $table->uuid('consumer_uuid')->nullable()->comment('MIS UUID at the eHealth side');
            $table->string('secret')->nullable()->comment('Legal Entity connection token');
            $table->string('redirect_uri')->nullable();
            $table->string('status')->default(ConnectionStatus::ACTIVE)->comment('Connection status at the MIS side');

            $table->date('ehealth_inserted_at')->nullable();
            $table->date('ehealth_updated_at')->nullable();

            $table->timestamps();

            $table->unique(['uuid', 'legal_entity_id'], 'client_connections_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_connections');
    }
};
