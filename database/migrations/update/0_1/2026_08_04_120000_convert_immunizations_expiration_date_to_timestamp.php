<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Widen the expiration date to keep the time it arrives with, so the immunization can be sent back
     * to eHealth exactly as it was stored.
     */
    public function up(): void
    {
        Schema::table('immunizations', function (Blueprint $table) {
            if (Schema::hasColumn('immunizations', 'expiration_date')) {
                $table->timestamp('expiration_date')->nullable()->change();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('immunizations', function (Blueprint $table) {
            if (Schema::hasColumn('immunizations', 'expiration_date')) {
                $table->date('expiration_date')->nullable()->change();
            }
        });
    }
};
