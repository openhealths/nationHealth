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
        if (!Schema::hasColumn('authentication_methods', 'url')) {
            Schema::table('authentication_methods', function (Blueprint $table) {
                // Here need to be text type because of the length of url commonly exceed the 255 characters
                $table->text('url')->nullable();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('authentication_methods', 'url')) {
            Schema::table('authentication_methods', function (Blueprint $table) {
                $table->dropColumn('url');
            });
        }
    }
};
