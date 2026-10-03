<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migration.
     */
    public function up(): void
    {
        $column = collect(Schema::getColumns('legal_entities'))
            ->firstWhere('name', 'legal_entity_type_id');

        if ($column === null || $column['nullable']) {
            return;
        }

        Schema::table('legal_entities', static function (Blueprint $table): void {
            $table->foreignId('legal_entity_type_id')->nullable()->change();
        });
    }

    /**
     * Reverse the migration.
     */
    public function down(): void
    {
        $column = collect(Schema::getColumns('legal_entities'))
            ->firstWhere('name', 'legal_entity_type_id');

        if ($column === null || ! $column['nullable']) {
            return;
        }

        Schema::table('legal_entities', static function (Blueprint $table): void {
            $table->foreignId('legal_entity_type_id')->nullable(false)->change();
        });
    }
};
