<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Widen the identifier value, which holds a free-form value for identifiers that name a resource
     * outside eHealth instead of referencing one by UUID.
     *
     * @return void
     */
    public function up(): void
    {
        if (!Schema::hasColumn('identifiers', 'value')) {
            return;
        }

        $valueColumn = collect(Schema::getColumns('identifiers'))->firstWhere('name', 'value');

        if ($valueColumn['type_name'] !== 'uuid') {
            return;
        }

        Schema::table('identifiers', static function (Blueprint $table) {
            $table->string('value')->change();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down(): void
    {
        if (!Schema::hasColumn('identifiers', 'value')) {
            return;
        }

        $valueColumn = collect(Schema::getColumns('identifiers'))->firstWhere('name', 'value');

        if ($valueColumn['type_name'] === 'uuid') {
            return;
        }

        // Postgres casts to a string type on its own, but casting back has to be spelled out
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('alter table "identifiers" alter column "value" type uuid using "value"::uuid');

            return;
        }

        Schema::table('identifiers', static function (Blueprint $table) {
            $table->uuid('value')->change();
        });
    }
};
