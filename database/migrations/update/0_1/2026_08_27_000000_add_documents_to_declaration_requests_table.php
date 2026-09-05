<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Keep the documents a declaration request expects before its approval, together with the upload URL of each
     * one, because they are returned only once — in the answer to creating the request.
     *
     * @return void
     */
    public function up(): void
    {
        if (Schema::hasColumn('declaration_requests', 'documents')) {
            return;
        }

        Schema::table('declaration_requests', static function (Blueprint $table): void {
            $table->jsonb('documents')
                ->nullable()
                ->after('data_to_be_signed')
                ->comment('Documents to upload before the approval, each with its own upload URL');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down(): void
    {
        if (!Schema::hasColumn('declaration_requests', 'documents')) {
            return;
        }

        Schema::table('declaration_requests', static function (Blueprint $table): void {
            $table->dropColumn('documents');
        });
    }
};
