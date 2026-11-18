<?php

declare(strict_types=1);

use App\Enums\Person\EncounterStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->setStatusConstraint(EncounterStatus::values());
    }

    public function down(): void
    {
        DB::table('encounters')
            ->where('status', EncounterStatus::DRAFT->value)
            ->update(['status' => EncounterStatus::FINISHED->value]);

        $this->setStatusConstraint(
            array_filter(
                EncounterStatus::values(),
                static fn (string $value): bool => $value !== EncounterStatus::DRAFT->value
            )
        );
    }

    /**
     * @param  array<int, string>  $values
     */
    protected function setStatusConstraint(array $values): void
    {
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            Schema::table('encounters', static function (Blueprint $table) use ($values): void {
                $table->enum('status', $values)->change();
            });

            return;
        }

        DB::statement('ALTER TABLE encounters ' . 'DROP CONSTRAINT IF EXISTS encounters_status_check');
        $statuses = implode("', '", $values);
        DB::statement("ALTER TABLE encounters " . "ADD CONSTRAINT encounters_status_check " . "CHECK (status IN ('$statuses'))");
    }
};
