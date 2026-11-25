<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        foreach (['status_history', 'ehealth_inserted_at', 'ehealth_inserted_by', 'ehealth_updated_at', 'ehealth_updated_by'] as $column) {
            if (!Schema::hasColumn('care_plans', $column)) {
                Schema::table('care_plans', static function (Blueprint $table) use ($column): void {
                    if ($column === 'status_history') {
                        $table->json($column)->nullable();
                    } elseif (str_ends_with($column, '_at')) {
                        $table->timestampTz($column, precision: 6)->nullable();
                    } else {
                        $table->uuid($column)->nullable();
                    }
                });
            }
        }
    }

    public function down(): void
    {
        Schema::table('care_plans', static function (Blueprint $table): void {
            $table->dropColumn(['status_history', 'ehealth_inserted_at', 'ehealth_inserted_by', 'ehealth_updated_at', 'ehealth_updated_by']);
        });
    }
};
