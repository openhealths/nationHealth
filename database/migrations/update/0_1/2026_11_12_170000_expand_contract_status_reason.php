<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contracts', function (Blueprint $table): void {
            $table->text('status_reason')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Keep the wider type on rollback so previously received explanations cannot be truncated.
    }
};
