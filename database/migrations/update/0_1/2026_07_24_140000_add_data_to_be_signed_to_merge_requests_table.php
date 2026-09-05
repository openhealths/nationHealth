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
     * Stores the consent document returned on approve so an APPROVED merge request can be resumed and signed
     * later without re-approving.
     *
     * @return void
     */
    public function up(): void
    {
        if (Schema::hasColumn('merge_requests', 'data_to_be_signed')) {
            return;
        }

        Schema::table('merge_requests', static function (Blueprint $table): void {
            $table->json('data_to_be_signed')
                ->nullable()
                ->after('status')
                ->comment('Consent document returned on approve, kept to resume signing an approved request');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down(): void
    {
        if (!Schema::hasColumn('merge_requests', 'data_to_be_signed')) {
            return;
        }

        Schema::table('merge_requests', static function (Blueprint $table): void {
            $table->dropColumn('data_to_be_signed');
        });
    }
};
