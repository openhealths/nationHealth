<?php

declare(strict_types=1);

use App\Enums\Person\DiagnosticReportStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds 'draft' to the allowed diagnostic report statuses.
     */
    public function up(): void
    {
        $this->setStatusConstraint(DiagnosticReportStatus::values());
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('diagnostic_reports')
            ->where('status', DiagnosticReportStatus::DRAFT->value)
            ->update(['status' => DiagnosticReportStatus::FINAL->value]);

        $this->setStatusConstraint(array_filter(
            DiagnosticReportStatus::values(),
            static fn (string $value): bool => $value !== DiagnosticReportStatus::DRAFT->value
        ));
    }

    /**
     * Restrict the diagnostic_reports status column to the given values.
     *
     * @param  array<int, string>  $values
     * @return void
     */
    private function setStatusConstraint(array $values): void
    {
        DB::statement('ALTER TABLE diagnostic_reports DROP CONSTRAINT IF EXISTS diagnostic_reports_status_check');

        $list = implode("', '", $values);

        DB::statement("ALTER TABLE diagnostic_reports ADD CONSTRAINT diagnostic_reports_status_check CHECK (status IN ('$list'))");
    }
};
