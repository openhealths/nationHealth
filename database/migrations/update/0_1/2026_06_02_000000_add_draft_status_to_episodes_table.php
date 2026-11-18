<?php

declare(strict_types=1);

use App\Enums\Episode\Status;
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
        $this->setStatusConstraint(Status::values());
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (['episodes', 'episode_status_history'] as $table) {
            if ($table === 'episode_status_history' && !Schema::hasTable($table)) {
                continue;
            }
            DB::table($table)
                ->where('status', Status::DRAFT->value)
                ->update(['status' => Status::ACTIVE->value]);
        }

        $this->setStatusConstraint(array_filter(
            Status::values(),
            static fn (string $value): bool => $value !== Status::DRAFT->value
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
    protected function setStatusConstraint(array $values): void
    {
        foreach (['episodes', 'episode_status_history'] as $name) {
            if ($name === 'episode_status_history' && !Schema::hasTable($name)) {
                continue;
            }
            if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
                Schema::table($name, static function (Blueprint $table) use ($values): void {
                    $table->enum('status', $values)->change();
                });

                continue;
            }

            Schema::table($name, static function (Blueprint $table) use ($name): void {
                $table->dropForeign($name.'_status_check');
            });

            $list = implode("', '", $values);

            DB::statement("ALTER TABLE {$name} ADD CONSTRAINT {$name}_status_check CHECK (status IN ('$list'))");
        }
    }
};
