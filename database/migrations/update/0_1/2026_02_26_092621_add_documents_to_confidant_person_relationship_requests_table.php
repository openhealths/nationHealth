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
        Schema::table('confidant_person_relationship_requests', function (Blueprint $table) {
            if (! Schema::hasColumn('confidant_person_relationship_requests', 'documents')) {
                $table->jsonb('documents')->nullable()->after('channel');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('confidant_person_relationship_requests', function (Blueprint $table) {
            if (Schema::hasColumn('confidant_person_relationship_requests', 'documents')) {
                $table->dropColumn('documents');
            }
        });
    }
};
