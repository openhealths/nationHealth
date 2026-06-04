<?php

declare(strict_types=1);

use App\Enums\Person\EpisodeStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds 'draft' to the allowed episode statuses.
     */
    public function up(): void
    {
        $this->setStatusConstraint(EpisodeStatus::values());
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('episodes')
            ->where('status', EpisodeStatus::DRAFT->value)
            ->update(['status' => EpisodeStatus::ACTIVE->value]);

        $this->setStatusConstraint(array_filter(
            EpisodeStatus::values(),
            static fn (string $value): bool => $value !== EpisodeStatus::DRAFT->value
        ));
    }

    /**
     * Restrict the episodes status column to the given values.
     *
     * The old CHECK constraint is dropped through the schema builder, then recreated
     * with a raw statement because Blueprint has no API for CHECK constraints.
     *
     * @param  array<int, string>  $values
     * @return void
     */
    private function setStatusConstraint(array $values): void
    {
        Schema::table('episodes', static function (Blueprint $table): void {
            $table->dropForeign('episodes_status_check');
        });

        $list = implode("', '", $values);

        DB::statement("ALTER TABLE episodes ADD CONSTRAINT episodes_status_check CHECK (status IN ('$list'))");
    }
};
