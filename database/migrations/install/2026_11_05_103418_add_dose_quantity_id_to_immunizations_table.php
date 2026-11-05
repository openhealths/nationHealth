<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Keep the immunization dose in the shared quantities table, which is created after the immunizations one.
     *
     * @return void
     */
    public function up(): void
    {
        if (!Schema::hasColumn('immunizations', 'dose_quantity_id')) {
            Schema::table('immunizations', static function (Blueprint $table): void {
                $table->foreignId('dose_quantity_id')->nullable()->constrained('quantities');
            });
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down(): void
    {
        if (Schema::hasColumn('immunizations', 'dose_quantity_id')) {
            Schema::table('immunizations', static function (Blueprint $table): void {
                $table->dropConstrainedForeignId('dose_quantity_id');
            });
        }
    }
};
