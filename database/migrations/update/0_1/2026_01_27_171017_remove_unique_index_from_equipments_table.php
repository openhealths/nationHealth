<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    protected const string TABLE_NAME = 'equipments';
    protected const string INDEX_NAME = 'equipments_legal_entity_id_inventory_number_unique';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table(self::TABLE_NAME, static function (Blueprint $table) {
            if (self::isUniqueIndexExists(self::TABLE_NAME, self::INDEX_NAME)) {
                $table->dropUnique(self::INDEX_NAME);
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table(self::TABLE_NAME, static function (Blueprint $table) {
            if (!self::isUniqueIndexExists(self::TABLE_NAME, self::INDEX_NAME)) {
                $table->unique(
                    ['legal_entity_id', 'inventory_number'],
                    self::INDEX_NAME
                );
            }
        });
    }

    protected static function isUniqueIndexExists(string $table, string $index): bool
    {
        $indexes = Schema::getIndexes($table);

        foreach ($indexes as $idx) {
            if ($idx['name'] === $index && $idx['unique'] === true) {
                return true;
            }
        }

        return false;
    }
};
