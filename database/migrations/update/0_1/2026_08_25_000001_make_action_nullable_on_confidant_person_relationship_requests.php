<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Allow the action to be empty, because the Get Confidant Person relationship requests list method
     * does not return it, unlike the answer to creating or deactivating a single request.
     *
     * @return void
     */
    public function up(): void
    {
        if (!Schema::hasColumn('confidant_person_relationship_requests', 'action')) {
            return;
        }

        Schema::table('confidant_person_relationship_requests', static function (Blueprint $table): void {
            $table->string('action')->nullable()
                ->comment('Left empty for the requests read from the listing, which does not carry it')
                ->change();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down(): void
    {
        if (!Schema::hasColumn('confidant_person_relationship_requests', 'action')) {
            return;
        }

        Schema::table('confidant_person_relationship_requests', static function (Blueprint $table): void {
            $table->string('action')->nullable(false)->change();
        });
    }
};
