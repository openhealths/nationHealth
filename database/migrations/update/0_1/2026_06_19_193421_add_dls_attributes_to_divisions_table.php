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
        Schema::table('divisions', function (Blueprint $table) {
            if (!Schema::hasColumn('divisions', 'dls_id')) {
                $table->string('dls_id')->nullable()->comment('DLS ID for the division');
            }

            if (!Schema::hasColumn('divisions', 'dls_verified')) {
                $table->boolean('dls_verified')->nullable()->comment('Indicates whether the division has been verified by DLS');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('divisions', function (Blueprint $table) {
            if (Schema::hasColumn('divisions', 'dls_id')) {
                $table->dropColumn('dls_id');
            }

            if (Schema::hasColumn('divisions', 'dls_verified')) {
                $table->dropColumn('dls_verified');
            }
        });
    }
};
