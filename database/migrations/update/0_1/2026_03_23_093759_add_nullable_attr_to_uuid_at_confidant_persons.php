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
        $uuidColumn = collect(Schema::getColumns('confidant_persons'))->where('name', 'uuid')->first();

        if (!$uuidColumn['nullable']) {
            // Change the uuid column to be nullable
            Schema::table('confidant_persons', function (Blueprint $table) {
                $table->uuid('uuid')->nullable()->change();
            });
        }
    }

    /**
     * Reverse the migrations.
     * Change the uuid column to be not nullable
     */
    public function down(): void
    {
        $uuidColumn = collect(Schema::getColumns('confidant_persons'))->where('name', 'uuid')->first();

        if ($uuidColumn['nullable']) {
            Schema::table('confidant_persons', function (Blueprint $table) {
                $table->uuid('uuid')->nullable(false)->default(null)->change();
            });
        }
    }
};
