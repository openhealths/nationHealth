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
    private function setStatusConstraint(array $values): void
    {
        Schema::table('declarations', static function (Blueprint $table): void {
            $table->dropForeign('declarations_status_check');
        });

        $list = implode("', '", $values);

        DB::statement("ALTER TABLE declarations ADD CONSTRAINT declarations_status_check CHECK (status IN ('$list'))");
    }
};
