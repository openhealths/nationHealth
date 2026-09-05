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
        Schema::table('encounters', static function (Blueprint $table) {
            if (!Schema::hasColumn('encounters', 'cancellation_reason_id')) {
                $table->foreignId('cancellation_reason_id')
                    ->after('status')
                    ->nullable()
                    ->constrained('codeable_concepts');
            }
            if (Schema::hasColumn('encounters', 'cancellation_reason')) {
                $table->dropColumn('cancellation_reason');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('encounters', static function (Blueprint $table) {
            if (!Schema::hasColumn('encounters', 'cancellation_reason')) {
                $table->string('cancellation_reason')->after('status')->nullable();
            }
            if (Schema::hasColumn('encounters', 'cancellation_reason_id')) {
                $table->dropForeign(['cancellation_reason_id']);
                $table->dropColumn('cancellation_reason_id');
            }
        });
    }
};
