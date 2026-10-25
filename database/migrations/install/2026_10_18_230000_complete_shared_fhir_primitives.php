<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['display', 'version', 'user_selected'] as $column) {
            if (!Schema::hasColumn('codings', $column)) {
                Schema::table('codings', static function (Blueprint $table) use ($column): void {
                    $column === 'user_selected' ? $table->boolean($column)->nullable() : $table->string($column)->nullable();
                });
            }
        }
        Schema::table('periods', static function (Blueprint $table): void {
            $table->timestamp('start', 6)->nullable()->change();
            $table->timestamp('end', 6)->nullable()->change();
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Shared FHIR precision requires a forward fix or pre-cutover backup.');
    }
};
