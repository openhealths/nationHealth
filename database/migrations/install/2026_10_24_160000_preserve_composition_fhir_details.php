<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('compositions')) {
            return;
        }
        if (!Schema::hasColumn('compositions', 'fhir_metadata')) {
            Schema::table('compositions', static fn (Blueprint $table) => $table->jsonb('fhir_metadata')->nullable());
        }
        Schema::table('compositions', static fn (Blueprint $table) => $table->timestampTz('date', 6)->nullable()->change());
        Schema::table('periods', static function (Blueprint $table): void {
            $table->timestampTz('start', 6)->nullable()->change();
            $table->timestampTz('end', 6)->nullable()->change();
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Composition fidelity storage requires a forward fix or restoration of the pre-cutover backup.');
    }
};
