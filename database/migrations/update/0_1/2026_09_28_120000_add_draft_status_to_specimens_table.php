<?php

declare(strict_types=1);

use App\Enums\Specimen\Status;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds 'draft' to the allowed specimen statuses and lets a specimen exist without an encounter.
     *
     * @return void
     */
    public function up(): void
    {
        if (!Schema::hasColumn('specimens', 'status')) {
            return;
        }

        $this->setStatusConstraint(Status::values());

        Schema::table('specimens', static function (Blueprint $table): void {
            $table->foreignId('context_id')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down(): void
    {
        if (!Schema::hasColumn('specimens', 'status')) {
            return;
        }

        DB::table('specimens')->where('status', Status::DRAFT->value)->delete();

        $this->setStatusConstraint(array_filter(
            Status::values(),
            static fn (string $value): bool => $value !== Status::DRAFT->value
        ));
    }

    /**
     * Restrict the specimens status column to the given values.
     *
     * @param  array  $values
     * @return void
     */
    protected function setStatusConstraint(array $values): void
    {
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            Schema::table('specimens', static function (Blueprint $table) use ($values): void {
                $table->enum('status', $values)->change();
            });

            return;
        }

        DB::statement('ALTER TABLE specimens DROP CONSTRAINT IF EXISTS specimens_status_check');

        $list = implode("', '", $values);

        DB::statement("ALTER TABLE specimens ADD CONSTRAINT specimens_status_check CHECK (status IN ('$list'))");
    }
};
