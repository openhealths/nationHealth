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
        Schema::table('confidant_persons', function (Blueprint $table) {
             if (! Schema::hasColumn('confidant_persons', 'active_to')) {
                $table->date('active_to')->nullable()->after('person_request_id');
             }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('confidant_persons', function (Blueprint $table) {
            if (Schema::hasColumn('confidant_persons', 'active_to')) {
                $table->dropColumn('active_to');
            }
        });
    }
};
