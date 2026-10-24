<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Fresh install runs before historical updates; the September update narrows this column.
// This update-only forward repair must run after it even if the new install schema was recorded.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('medication_request_requests', static function (Blueprint $table): void {
            $table->decimal('medication_qty', 24, 10)->nullable()->change();
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Narrowing medication quantity would lose clinical precision.');
    }
};
