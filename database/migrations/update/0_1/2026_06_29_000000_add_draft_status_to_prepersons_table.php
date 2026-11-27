<?php

declare(strict_types=1);

use App\Enums\Preperson\Status;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds 'draft' to the allowed preperson statuses.
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
        DB::table('prepersons')
            ->where('status', Status::DRAFT->value)
            ->update(['status' => Status::ACTIVE->value]);

        $this->setStatusConstraint(array_filter(
            Status::values(),
            static fn (string $value): bool => $value !== Status::DRAFT->value
        ));
    }

    /**
     * Restrict the prepersons status column to the given values.
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
            Schema::table('prepersons', static function (Blueprint $table) use ($values): void {
                $table->enum('status', $values)->nullable()->change();
            });

            return;
        }

        Schema::table('prepersons', static function (Blueprint $table): void {
            $table->dropForeign('prepersons_status_check');
        });

        $list = implode("', '", $values);

        DB::statement("ALTER TABLE prepersons ADD CONSTRAINT prepersons_status_check CHECK (status IN ('$list'))");
    }
};
