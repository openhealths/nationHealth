<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Medical-event tables that gain a preperson owner alongside person.
     * Add each new entity's table here as the dual-FK rollout continues.
     *
     * @var array
     */
    private array $tables = [
        'encounters',
        'conditions',
        'episodes',
        'observations',
        'diagnostic_reports',
        'immunizations',
        'procedures',
        'clinical_impressions'
    ];

    /**
     * Add a preperson owner alongside person and relax person_id to nullable,
     * so these records can belong to either a person or a preperson.
     *
     * @return void
     */
    public function up(): void
    {
        foreach ($this->tables as $table) {
            if (!Schema::hasColumn($table, 'preperson_id')) {
                Schema::table($table, static function (Blueprint $blueprint): void {
                    $blueprint->foreignId('preperson_id')->nullable()->after('person_id')->constrained('prepersons');
                });
            }

            Schema::table($table, static function (Blueprint $blueprint): void {
                $blueprint->unsignedBigInteger('person_id')->nullable()->change();
            });
        }
    }

    /**
     * Drop the preperson owner and restore person_id as required.
     *
     * @return void
     */
    public function down(): void
    {
        foreach ($this->tables as $table) {
            if (Schema::hasColumn($table, 'preperson_id')) {
                Schema::table($table, static function (Blueprint $blueprint): void {
                    $blueprint->dropConstrainedForeignId('preperson_id');
                });
            }

            Schema::table($table, static function (Blueprint $blueprint): void {
                $blueprint->unsignedBigInteger('person_id')->nullable(false)->change();
            });
        }
    }
};
