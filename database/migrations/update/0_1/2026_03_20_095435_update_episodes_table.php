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
        Schema::table('episodes', function (Blueprint $table) {
            $table->foreignId('person_id')->after('uuid')->constrained('persons');
            $table->foreignId('episode_type_id')->nullable()->change();
            $table->foreignId('managing_organization_id')->nullable()->change();
            $table->foreignId('care_manager_id')->nullable()->change();
            $table->timestamp('ehealth_inserted_at')->nullable()->after('care_manager_id');
            $table->timestamp('ehealth_updated_at')->nullable()->after('ehealth_inserted_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('episodes', function (Blueprint $table) {
            $table->dropColumn(['ehealth_updated_at', 'ehealth_inserted_at']);
            $table->foreignId('episode_type_id')->nullable(false)->change();
            $table->foreignId('managing_organization_id')->nullable(false)->change();
            $table->foreignId('care_manager_id')->nullable(false)->change();
            $table->dropForeign(['person_id']);
            $table->dropColumn('person_id');
        });
    }
};
