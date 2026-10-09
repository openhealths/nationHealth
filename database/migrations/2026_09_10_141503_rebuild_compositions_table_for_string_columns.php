<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        (require __DIR__ . '/install/2026_08_07_210751_create_compositions_table.php')->up();
    }

    public function down(): void
    {
        // Historical migration: never discard an existing clinical table.
    }
};
