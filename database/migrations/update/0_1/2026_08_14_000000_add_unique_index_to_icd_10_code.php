<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Replaces the plain index on icd_10.code with a unique one, so the dictionary refresh can upsert on it.
     *
     * @return void
     */
    public function up(): void
    {
        if (!Schema::hasTable('icd_10') || !Schema::hasColumn('icd_10', 'code')) {
            return;
        }

        Schema::table('icd_10', static function (Blueprint $table): void {
            if (Schema::hasIndex('icd_10', 'icd_10_code_index')) {
                $table->dropIndex('icd_10_code_index');
            }

            if (!Schema::hasIndex('icd_10', 'icd_10_code_unique')) {
                $table->unique('code');
            }
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down(): void
    {
        if (!Schema::hasTable('icd_10') || !Schema::hasColumn('icd_10', 'code')) {
            return;
        }

        Schema::table('icd_10', static function (Blueprint $table): void {
            if (Schema::hasIndex('icd_10', 'icd_10_code_unique')) {
                $table->dropUnique('icd_10_code_unique');
            }

            if (!Schema::hasIndex('icd_10', 'icd_10_code_index')) {
                $table->index('code');
            }
        });
    }
};
