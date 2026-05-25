<?php

declare(strict_types=1);

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
        Schema::create('paper_referrals', static function (Blueprint $table) {
            $table->id();
            $table->string('requisition')->nullable();
            $table->string('requester_legal_entity_name')->nullable();
            $table->string('requester_legal_entity_edrpou');
            $table->string('requester_employee_name');
            $table->dateTime('service_request_date');
            $table->text('note')->nullable();
            $table->morphs('paper_referralable', 'ppr_morph');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('paper_referrals');
    }
};
