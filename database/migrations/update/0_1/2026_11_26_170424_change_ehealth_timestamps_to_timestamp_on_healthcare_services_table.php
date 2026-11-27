<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Columns of the time the healthcare service was registered and last changed.
     *
     * @var array
     */
    protected array $columns = ['ehealth_inserted_at', 'ehealth_updated_at'];

    /**
     * Store the time along with the date, so a service registered at night isn't shown with the previous day.
     * The stored dates become midnight in UTC until the next synchronization brings the time.
     *
     * @return void
     */
    public function up(): void
    {
        foreach ($this->columns as $column) {
            if (Schema::hasColumn('healthcare_services', $column) && Schema::getColumnType('healthcare_services', $column) === 'date') {
                Schema::table('healthcare_services', static function (Blueprint $table) use ($column): void {
                    $table->timestamp($column)->nullable()->change();
                });
            }
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down(): void
    {
        foreach ($this->columns as $column) {
            if (Schema::hasColumn('healthcare_services', $column) && Schema::getColumnType('healthcare_services', $column) === 'timestamp') {
                Schema::table('healthcare_services', static function (Blueprint $table) use ($column): void {
                    $table->date($column)->nullable()->change();
                });
            }
        }
    }
};
