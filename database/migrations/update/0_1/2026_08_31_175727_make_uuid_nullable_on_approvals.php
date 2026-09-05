<?php

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
        $uuidColumn = collect(Schema::getColumns('approvals'))->where('name', 'uuid')->first();

        if (!$uuidColumn['nullable']) {
            // Change the uuid column to be nullable
            Schema::table('approvals', function (Blueprint $table) {
                $table->uuid('uuid')->nullable()->change();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $uuidColumn = collect(Schema::getColumns('approvals'))->where('name', 'uuid')->first();

        if ($uuidColumn['nullable']) {
            Schema::table('approvals', function (Blueprint $table) {
                $table->uuid('uuid')->nullable(false)->default(null)->change();
            });
        }
    }
};
