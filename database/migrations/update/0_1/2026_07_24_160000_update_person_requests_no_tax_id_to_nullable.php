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
     * @return void
     */
    public function up(): void
    {
        if (Schema::hasColumn('person_requests', 'no_tax_id')) {
            Schema::table('person_requests', static function (Blueprint $table): void {
                $table->boolean('no_tax_id')->nullable()->change();
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
        if (Schema::hasColumn('person_requests', 'no_tax_id')) {
            Schema::table('person_requests', static function (Blueprint $table): void {
                $table->boolean('no_tax_id')->nullable(false)->change();
            });
        }
    }
};
