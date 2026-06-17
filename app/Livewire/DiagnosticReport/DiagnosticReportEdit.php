<?php

declare(strict_types=1);

namespace App\Livewire\DiagnosticReport;

use App\Classes\eHealth\EHealth;
use App\Classes\Cipher\Api\CipherRequest;
use App\Core\Arr;
use App\Enums\Person\DiagnosticReportStatus;
use App\Exceptions\EHealth\EHealthConnectionException;
use App\Exceptions\EHealth\EHealthException;
use App\Exceptions\Cipher\CipherConnectionException;
use App\Exceptions\Cipher\CipherException;
use App\Models\LegalEntity;
use App\Models\MedicalEvents\Sql\DiagnosticReport;
use App\Repositories\MedicalEvents\Repository;
use App\Services\MedicalEvents\Fhir;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Throwable;

class DiagnosticReportEdit extends DiagnosticReportComponent
{
    #[Locked]
    public string $diagnosticReportId;

    #[Locked]
    public string $diagnosticReportUuid;

    public function mount(LegalEntity $legalEntity, int $personId, ?string $diagnosticReportId = null): void 
    {
        parent::mount($legalEntity, $personId);
        $this->diagnosticReportId = $diagnosticReportId;
        $diagnosticReport = DiagnosticReport::withAllRelations()
            ->whereKey($diagnosticReportId)
            ->where('person_id', $personId)
            ->firstOrFail();

        $this->diagnosticReportUuid = $diagnosticReport->uuid;

        $diagnosticReportData = Fhir::diagnosticReport()->fromFhir(
            $diagnosticReport->toArray()
        );

        $conclusionCode = data_get($diagnosticReportData, 'conclusionCode');

        if ($conclusionCode) {
            $icd10Items = [
                [
                    'codeSystem' => 'eHealth/ICD10_AM/condition_codes',
                    'codeCode' => $conclusionCode,
                ],
            ];

            $this->loadIcd10Descriptions($icd10Items);

            $description = $this->dictionaries['eHealth/ICD10_AM/condition_codes'][$conclusionCode] ?? null;

            $diagnosticReportData['conclusionCodeLabel'] = $description
                ? $conclusionCode . ' - ' . $description
                : $conclusionCode;
        }

        $this->form->diagnosticReport = $diagnosticReportData;

        $this->form->diagnosticReport['usedReferences'] = $this->form->diagnosticReport['usedReferences'] ?? [];

        $this->form->observations = collect(Repository::observation()->getByDiagnosticReportId($diagnosticReportId))
            ->map(fn (array $observation) => Fhir::observation()->fromFhir($observation))
            ->toArray();
    }

    public function save(array $diagnosticReportData): void
    {
        $formattedData = $this->buildFormattedData($diagnosticReportData, DiagnosticReportStatus::DRAFT);

        if ($formattedData === null) {
            return;
        }

        try {
            $this->syncValidatedData($formattedData);
        } catch (Throwable $exception) {
            $this->handleDatabaseErrors($exception, 'Error while updating diagnostic report');

            return;
        }

        Session::flash('success', __('patients.messages.diagnostic_report_draft_saved'));

        $this->redirectRoute(
            'diagnostic-report.edit',
            [legalEntity(), 'personId' => $this->personId, 'diagnosticReportId' => $this->diagnosticReportId],
            navigate: true
        );
    }

    public function sign(array $diagnosticReportData): void
    {
        try {
            $validatedCipher = $this->form->validate($this->form->signingRules());
        } catch (ValidationException $exception) {
            Session::flash('error', $exception->validator->errors()->first());
            $this->setErrorBag($exception->validator->getMessageBag());

            return;
        }

        $formattedData = $this->buildFormattedData($diagnosticReportData, DiagnosticReportStatus::FINAL);

        if ($formattedData === null) {
            return;
        }

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

            $this->syncValidatedData($formattedData);

            Session::flash('success', __('patients.messages.diagnostic_report_create_request_sent'));
            $this->redirectRoute(
                'diagnostic-report.edit',
                [legalEntity(), 'personId' => $this->personId, 'diagnosticReportId' => $this->diagnosticReportId],
                navigate: true
            );
        } catch (EHealthException|EHealthConnectionException $exception) {
            $exception->handle('Error when signing diagnostic report');

            return;
        } catch (Throwable $exception) {
            $this->handleDatabaseErrors($exception, 'Error while saving diagnostic report');

            return;
        }
    }

    private function buildFormattedData(array $diagnosticReportData, DiagnosticReportStatus $status): ?array 
    {
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

            return null;
        }

        return $this->prepareFormattedData($validated, $status, $this->diagnosticReportUuid);
    }

    protected function syncValidatedData(array $formattedData): void
    {
        DB::transaction(function () use ($formattedData) {
            Repository::diagnosticReport()->sync(
                $this->personId,
                [$this->fhirToSync($formattedData['diagnosticReport'])]
            );

            Repository::observation()->sync(
                $this->personId,
                array_map($this->fhirToSync(...), $formattedData['observations'] ?? [])
            );
        });
    }

    private function fhirToSync(array $fhirItem): array
    {
        return Arr::toSnakeCase(
            collect($fhirItem)
                ->put('uuid', $fhirItem['id'])
                ->forget(['id'])
                ->all()
        );
    }
}