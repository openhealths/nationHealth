<?php

declare(strict_types=1);

use App\Enums\MergeRequest\Status;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds 'CANCELLED' to the allowed merge request statuses.
     *
     * @return void
     */
    public function up(): void
    {
        if (!Schema::hasColumn('merge_requests', 'status')) {
            return;
        }

        $this->setStatusConstraint(Status::values());
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down(): void
    {
        if (!Schema::hasColumn('merge_requests', 'status')) {
            return;
        }

        DB::table('merge_requests')
            ->where('status', Status::CANCELLED->value)
            ->update(['status' => Status::REJECTED->value]);

        $this->setStatusConstraint(array_filter(
            Status::values(),
            static fn (string $value): bool => $value !== Status::CANCELLED->value
        ));
    }

    /**
     * Restrict the merge_requests status column to the given values.
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
            Schema::table('merge_requests', static function (Blueprint $table) use ($values): void {
                $table->enum('status', $values)->change();
            });

            return;
        }

        Schema::table('merge_requests', static function (Blueprint $table): void {
            $table->dropForeign('merge_requests_status_check');
        });

        $list = implode("', '", $values);

        DB::statement("ALTER TABLE merge_requests ADD CONSTRAINT merge_requests_status_check CHECK (status IN ('$list'))");
    }
};
