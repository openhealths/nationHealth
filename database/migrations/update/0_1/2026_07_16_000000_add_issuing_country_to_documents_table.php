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
        if (Schema::hasColumn('documents', 'issuing_country')) {
            return;
        }

        Schema::table('documents', static function (Blueprint $table): void {
            $table->string('issuing_country')
                ->nullable()
                ->after('issued_by')
                ->comment('Dictionary ISSUING_COUNTRY - country that issued the document');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down(): void
    {
        if (!Schema::hasColumn('documents', 'issuing_country')) {
            return;
        }

        Schema::table('documents', static function (Blueprint $table): void {
            $table->dropColumn('issuing_country');
        });
    }
};
