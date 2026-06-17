<?php

declare(strict_types=1);

namespace App\Livewire\DiagnosticReport;

use App\Classes\eHealth\EHealth;
use App\Classes\Cipher\Api\CipherRequest;
use App\Exceptions\EHealth\EHealthConnectionException;
use App\Exceptions\EHealth\EHealthException;
use App\Exceptions\Cipher\CipherConnectionException;
use App\Exceptions\Cipher\CipherException;
use App\Enums\Person\DiagnosticReportStatus;
use App\Models\MedicalEvents\Sql\DiagnosticReport;
use App\Core\Arr;
use App\Repositories\MedicalEvents\Repository;
use Exception;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\ValidationException;
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

        $employee = Auth::user()->getDiagnosticReportWriterEmployee();
        if (!$employee) {
            Session::flash('error', __('patients.messages.diagnostic_report_writer_employee_not_found'));
            return;
        }

        $diagnosticReportData['usedReferences'] = array_values(array_filter(
            $diagnosticReportData['usedReferences'] ?? [],
            static fn (array $usedReference) => filled($usedReference['id'] ?? null)
        ));

        $this->form->diagnosticReport = $diagnosticReportData;

        try {
            $validated = $this->form->validate();
        } catch (ValidationException $exception) {
            Session::flash('error', $exception->validator->errors()->first());
            $this->setErrorBag($exception->validator->getMessageBag());

            return;
        }

        $formattedData = $this->prepareFormattedData($validated, DiagnosticReportStatus::DRAFT);

        try {
            $diagnosticReportId = $this->storeValidatedData($formattedData);
        } catch (Exception|Throwable $exception) {
            $this->handleDatabaseErrors($exception, 'Error while saving diagnostic report');

            return;
        }

        Session::flash('success', __('patients.messages.diagnostic_report_draft_saved'));
        $this->redirectRoute(
            'diagnostic-report.edit',
            [
                legalEntity(),
                'personId' => $this->personId,
                'diagnosticReportId' => $diagnosticReportId,
            ],
            navigate: true
        );
    }

    /**
     * Submit encrypted data.
     *
     * @param  array  $diagnosticReportData
     * @return void
     */
    public function sign(array $diagnosticReportData): void
    {
        if (Auth::user()->cannot('create', DiagnosticReport::class)) {
            Session::flash('error', __('patients.policy.create_diagnostic_report'));

            return;
        }

        $diagnosticReportData['usedReferences'] = array_values(array_filter(
            $diagnosticReportData['usedReferences'] ?? [],
            static fn (array $usedReference) => filled($usedReference['id'] ?? null)
        ));

        $this->form->diagnosticReport = $diagnosticReportData;

        try {
            $validated = $this->form->validate();
            $validatedCipher = $this->form->validate($this->form->signingRules());
        } catch (ValidationException $exception) {
            Session::flash('error', $exception->validator->errors()->first());
            $this->setErrorBag($exception->validator->getMessageBag());

            return;
        }

        $formattedData = $this->prepareFormattedData($validated, DiagnosticReportStatus::FINAL);

        try {
            $signedContent = new CipherRequest()->signData(
                Arr::toSnakeCase($formattedData),
                $validatedCipher['knedp'],
                $validatedCipher['keyContainerUpload'],
                $validatedCipher['password'],
                Auth::user()->party->taxId
            );
        } catch (CipherException|CipherConnectionException $exception) {
            $exception->handle('Error when signing diagnostic report with Cipher');

            return;
        }

        try {
            EHealth::diagnosticReport()->create($this->patientUuid, ['signed_data' => $signedContent->getBase64Data()]);

            $diagnosticReportId = $this->storeValidatedData($formattedData);

            Session::flash('success', __('patients.messages.diagnostic_report_create_request_sent'));
            $this->redirectRoute(
                'diagnostic-report.edit',
                [
                    legalEntity(),
                    'personId' => $this->personId,
                    'diagnosticReportId' => $diagnosticReportId,
                ],
                navigate: true
            );
        } catch (EHealthException|EHealthConnectionException $exception) {
            $exception->handle('Error when creating a diagnostic report');

            return;
        } catch (Throwable $exception) {
            $this->handleDatabaseErrors($exception, 'Error while saving diagnostic report');

            return;
        }
    }

    /**
     * Store validated formatted data into DB.
     *
     * @param  array  $formattedData
     * @return int
     * @throws Throwable
     */
    protected function storeValidatedData(array $formattedData): int
    {
        return DB::transaction(function () use ($formattedData) {
            $diagnosticReportId = Repository::diagnosticReport()->store([$formattedData['diagnosticReport']], $this->personId);

            if (isset($formattedData['observations'])) {
                Repository::observation()->store($formattedData['observations'], $this->personId, diagnosticReportId: $diagnosticReportId);
            }

            return $diagnosticReportId;
        });
    }
}
