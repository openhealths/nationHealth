<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Preserve existing reports while completing the fields used by package cancellation and synchronization. */
    public function up(): void
    {
        foreach (['origin_episode_id', 'cancellation_reason_id'] as $column) {
            if (!Schema::hasColumn('diagnostic_reports', $column)) {
                $target = $column === 'origin_episode_id' ? 'identifiers' : 'codeable_concepts';
                Schema::table('diagnostic_reports', static fn (Blueprint $table) => $table->foreignId($column)->nullable()->constrained($target));
            }
        }
        if (!Schema::hasColumn('diagnostic_reports', 'explanatory_letter')) {
            Schema::table('diagnostic_reports', static fn (Blueprint $table) => $table->string('explanatory_letter')->nullable());
        }
        foreach (['ehealth_inserted_at', 'ehealth_updated_at'] as $column) {
            if (!Schema::hasColumn('diagnostic_reports', $column)) {
                Schema::table('diagnostic_reports', static fn (Blueprint $table) => $table->timestamp($column)->nullable());
            }
        }
    }

    public function down(): void
    {
        // The columns can already exist before this repair; rollback must retain clinical data.
    }
};
