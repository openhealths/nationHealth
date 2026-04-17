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
        if (!Schema::hasTable('diagnostic_report_used_references')) {
            Schema::create('diagnostic_report_used_references', static function (Blueprint $table) {
                $table->id();
                $table->foreignId('diagnostic_report_id')->constrained()->cascadeOnDelete();
                $table->foreignId('identifier_id')->constrained('identifiers')->cascadeOnDelete();
                $table->timestamps();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('diagnostic_report_used_references');
    }
};
