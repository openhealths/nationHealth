<?php

declare(strict_types=1);

use App\Enums\Person\ProcedureStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds 'draft' to the allowed procedure statuses.
     */
    public function up(): void
    {
        if (!Schema::hasColumn('procedures', 'status')) {
            return;
        }

        $this->setStatusConstraint(ProcedureStatus::values());
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (!Schema::hasColumn('procedures', 'status')) {
            return;
        }

        DB::table('procedures')
            ->where('status', ProcedureStatus::DRAFT->value)
            ->update(['status' => ProcedureStatus::COMPLETED->value]);

        $this->setStatusConstraint(array_filter(
            ProcedureStatus::values(),
            static fn (string $value): bool => $value !== ProcedureStatus::DRAFT->value
        ));
    }

    /**
     * Restrict the procedures status column to the given values.
     *
     * The old CHECK constraint is dropped through the schema builder, then recreated
     * with a raw statement because Blueprint has no API for CHECK constraints.
     *
     * @param  array<int, string>  $values
     * @return void
     */
    protected function setStatusConstraint(array $values): void
    {
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            Schema::table('procedures', static function (Blueprint $table) use ($values): void {
                $table->enum('status', $values)->change();
            });

            return;
        }

        Schema::table('procedures', static function (Blueprint $table): void {
            $table->dropForeign('procedures_status_check');
        });

        $list = implode("', '", $values);

        DB::statement("ALTER TABLE procedures ADD CONSTRAINT procedures_status_check CHECK (status IN ('$list'))");
    }
};
