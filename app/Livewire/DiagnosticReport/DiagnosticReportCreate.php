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
            Session::flash('error', __('diagnostic-reports.policy.create'));

            return;
        }

        if (!Auth::user()->getDiagnosticReportWriterEmployee()) {
            Session::flash('error', __('diagnostic-reports.messages.writer_employee_not_found'));

            return;
        }

        parent::save($diagnosticReportData);
    }

    /**
     * Submit encrypted data.
     *
     * @param  string  $keyContainer  Key container in base64
     * @param  string  $password
     * @return void
     */
    public function sign(string $keyContainer = '', string $password = ''): void
    {
        if (Auth::user()->cannot('create', DiagnosticReport::class)) {
            Session::flash('error', __('diagnostic-reports.policy.create'));

            return;
        }

        parent::sign($keyContainer, $password);
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
            $uuid = data_get($this->form->diagnosticReport, 'basedOnIdentifier');

            if (data_get($this->form->diagnosticReport, 'referralType') === 'electronic' && filled($uuid)) {
                $this->storeElectronicReferralIfMissing($uuid, Auth::user()->getDiagnosticReportWriterEmployee());
            }

            $diagnosticReportId = Repository::diagnosticReport()
                ->store([$formattedData['diagnosticReport']], $this->patient());

            if (isset($formattedData['observations'])) {
                Repository::observation()->store($formattedData['observations'], $this->patient(), $diagnosticReportId);
            }

            return $diagnosticReportId;
        });
    }
}
