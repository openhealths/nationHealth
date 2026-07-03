<?php

declare(strict_types=1);

namespace App\Livewire\DiagnosticReport;

use App\Models\MedicalEvents\Sql\DiagnosticReport;
use App\Repositories\MedicalEvents\Repository;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;
use Throwable;

class DiagnosticReportCreate extends DiagnosticReportComponent
{
    /**
     * Validate and save data.
     *
     * @param  array  $diagnosticReportData
     * @return void
     */
    public function save(array $diagnosticReportData): void
    {
        if (Auth::user()->cannot('create', DiagnosticReport::class)) {
            Session::flash('error', __('patients.policy.create_diagnostic_report'));

            return;
        }

        if (!Auth::user()->getDiagnosticReportWriterEmployee()) {
            Session::flash('error', __('patients.messages.diagnostic_report_writer_employee_not_found'));

            return;
        }

        parent::save($diagnosticReportData);
    }

    /**
     * Submit encrypted data.
     *
     * @return void
     */
    public function sign(): void
    {
        if (Auth::user()->cannot('create', DiagnosticReport::class)) {
            Session::flash('error', __('patients.policy.create_diagnostic_report'));

            return;
        }

        parent::sign();
    }

    /**
     * Store the formatted report and return its new identifier.
     *
     * @param  array  $formattedData
     * @return int
     * @throws Throwable
     */
    protected function persist(array $formattedData): int
    {
        return DB::transaction(function () use ($formattedData) {
            $diagnosticReportId = Repository::diagnosticReport()
                ->store([$formattedData['diagnosticReport']], $this->patient());

            if (isset($formattedData['observations'])) {
                Repository::observation()->store($formattedData['observations'], $this->patient(), $diagnosticReportId);
            }

            return $diagnosticReportId;
        });
    }
}
