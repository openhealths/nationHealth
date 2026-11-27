<?php

declare(strict_types=1);

use App\Enums\Declaration\Status;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Aligns the declarations status constraint with the DECLARATION_STATUSES
     * dictionary (active, closed, pending_verification, rejected, terminated).
     * Any legacy uppercase values left over from the previous shared enum are normalized first so the new constraint can be applied.
     */
    public function up(): void
    {
        if (!Schema::hasColumn('declarations', 'status')) {
            return;
        }

        if (DB::table('declarations')->whereNotIn('status', [...Status::values(), 'REJECTED', 'CANCELLED'])->exists()) {
            throw new RuntimeException('Unsupported legacy declaration statuses; resolve them before updating the status constraint.');
        }

        // The old constraint rejects the normalized values. Preserve the strings while converting them.
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            Schema::table('declarations', static function (Blueprint $table): void {
                $table->string('status')->change();
            });
        } else {
            DB::statement('ALTER TABLE declarations DROP CONSTRAINT IF EXISTS declarations_status_check');
        }

        DB::table('declarations')->where('status', 'REJECTED')->update(['status' => Status::REJECTED->value]);
        DB::table('declarations')->where('status', 'CANCELLED')->update(['status' => Status::CLOSED->value]);

        $this->setStatusConstraint(Status::values());
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (!Schema::hasColumn('declarations', 'status')) {
            return;
        }

        $this->setStatusConstraint(['DRAFT', 'NEW', 'APPROVED', 'SIGNED', 'active', 'terminated', 'REJECTED', 'CANCELLED']);
    }

    /**
     * Restrict the declarations status column to the given values.
     *
     * The old CHECK constraint is dropped through the schema builder, then recreated ith a raw statement
     * because Blueprint has no API for CHECK constraints.
     *
     * @param  array<int, string>  $values
     * @return void
     */
    protected function setStatusConstraint(array $values): void
    {
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            Schema::table('declarations', static function (Blueprint $table) use ($values): void {
                $table->enum('status', $values)->change();
            });

            return;
        }

        DB::statement('ALTER TABLE declarations DROP CONSTRAINT IF EXISTS declarations_status_check');

        $list = implode("', '", $values);

        DB::statement("ALTER TABLE declarations ADD CONSTRAINT declarations_status_check CHECK (status IN ('$list'))");
    }
};
