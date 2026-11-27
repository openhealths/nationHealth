<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('periods', static function (Blueprint $table): void {
            $table->timestampTz('start', 6)->nullable()->change();
            $table->timestampTz('end', 6)->nullable()->change();
        });
        Schema::table('quantities', static fn (Blueprint $table) => $table->decimal('value', 24, 10)
            ->comment('Numerical value (with implicit precision)')->change());
    }

    public function down(): void
    {
        throw new RuntimeException('Reducing clinical precision requires restoring the matching backup.');
    }
};
