<?php

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
            if (!Schema::hasColumn('divisions', 'ehealth_inserted_at')) {
                $table->date('ehealth_inserted_at')->nullable();
            }

            if (!Schema::hasColumn('divisions', 'inserted_by')) {
                $table->uuid('inserted_by')->nullable();
            }

            if (!Schema::hasColumn('divisions', 'ehealth_updated_at')) {
                $table->date('ehealth_updated_at')->nullable();
            }

            if (!Schema::hasColumn('divisions', 'updated_by')) {
                $table->uuid('updated_by')->nullable();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('divisions', function (Blueprint $table) {
            if (Schema::hasColumn('divisions', 'ehealth_inserted_at')) {
                $table->dropColumn('ehealth_inserted_at');
            }

            if (Schema::hasColumn('divisions', 'inserted_by')) {
                $table->dropColumn('inserted_by');
            }

            if (Schema::hasColumn('divisions', 'ehealth_updated_at')) {
                $table->dropColumn('ehealth_updated_at');
            }

            if (Schema::hasColumn('divisions', 'updated_by')) {
                $table->dropColumn('updated_by');
            }
        });
    }
};
