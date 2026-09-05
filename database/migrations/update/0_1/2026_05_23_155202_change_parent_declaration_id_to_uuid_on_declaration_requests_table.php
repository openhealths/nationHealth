<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasColumn('declaration_requests', 'parent_declaration_id')) {
            Schema::table('declaration_requests', function (Blueprint $table) {
                $table->renameColumn('parent_declaration_id', 'parent_declaration_uuid');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('declaration_requests', 'parent_declaration_uuid')) {
            Schema::table('declaration_requests', function (Blueprint $table) {
                $table->renameColumn('parent_declaration_uuid', 'parent_declaration_id');
            });
        }
    }
};
