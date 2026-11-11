<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Other modules retain the legacy column; care-plan approvals no longer populate it.
        Schema::table('approvals', static function (Blueprint $table): void {
            $table->string('status')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Rows created without a status cannot be made NOT NULL without inventing consent state.
        // Keep the compatible nullable column when rolling the application back.
    }
};
