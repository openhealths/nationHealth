<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * eHealth dropped settlement_type from the person address schema, so it no longer comes back in the response
     * a person address is stored from. Divisions and legal entities still fill it in.
     *
     * @return void
     */
    public function up(): void
    {
        if (!Schema::hasColumn('addresses', 'settlement_type')) {
            return;
        }

        Schema::table('addresses', static function (Blueprint $table): void {
            $table->string('settlement_type')
                ->nullable()
                ->comment('Dictionary SETTLEMENT_TYPE - type of settlement as city/town/village etc')
                ->change();
        });
    }

    /**
     * Reverse the migrations.
     *
     * Person addresses may contain null settlement_type after eHealth dropped the field.
     * Backfill those rows before restoring NOT NULL so rollback does not fail with SQLSTATE[23502].
     *
     * @return void
     */
    public function down(): void
    {
        if (!Schema::hasColumn('addresses', 'settlement_type')) {
            return;
        }

        DB::table('addresses')
            ->whereNull('settlement_type')
            ->update(['settlement_type' => '']);

        Schema::table('addresses', static function (Blueprint $table): void {
            $table->string('settlement_type')
                ->nullable(false)
                ->comment('Dictionary SETTLEMENT_TYPE - type of settlement as city/town/village etc')
                ->change();
        });
    }
};
