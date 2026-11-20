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
        Schema::table('equipments', static function (Blueprint $table) {
            $table->dateTime('ehealth_inserted_at')->nullable()->change();
            $table->dateTime('ehealth_updated_at')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('equipments', static function (Blueprint $table) {
            $table->date('ehealth_inserted_at')->nullable()->change();
            $table->date('ehealth_updated_at')->nullable()->change();
        });
    }
};