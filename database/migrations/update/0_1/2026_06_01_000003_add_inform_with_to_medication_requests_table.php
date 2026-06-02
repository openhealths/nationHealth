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
        Schema::table('medication_request_requests', static function (Blueprint $table) {
            if (!Schema::hasColumn('medication_request_requests', 'inform_with')) {
                $table->string('inform_with')->nullable();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('medication_request_requests', static function (Blueprint $table) {
            if (Schema::hasColumn('medication_request_requests', 'inform_with')) {
                $table->dropColumn('inform_with');
            }
        });
    }
};
