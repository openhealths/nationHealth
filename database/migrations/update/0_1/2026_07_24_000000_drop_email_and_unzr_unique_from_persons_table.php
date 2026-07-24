<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('persons', static function (Blueprint $table): void {
            if (Schema::hasIndex('persons', 'persons_email_unique')) {
                $table->dropUnique(['email']);
            }

            if (Schema::hasIndex('persons', 'persons_unzr_unique')) {
                $table->dropUnique(['unzr']);
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('persons', static function (Blueprint $table): void {
            if (!Schema::hasIndex('persons', 'persons_email_unique')) {
                $table->unique('email');
            }

            if (!Schema::hasIndex('persons', 'persons_unzr_unique')) {
                $table->unique('unzr');
            }
        });
    }
};
