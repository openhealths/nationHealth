<?php

declare(strict_types=1);

namespace App\Livewire\Person\Records;

use App\Classes\Cipher\Api\CipherRequest;
use App\Classes\eHealth\EHealth;
use App\Core\Arr;
use App\Enums\JobStatus;
use App\Enums\Person\DiagnosticReportStatus;
use App\Enums\Person\ObservationStatus;
use App\Exceptions\Cipher\CipherConnectionException;
use App\Exceptions\Cipher\CipherException;
use App\Exceptions\EHealth\EHealthConnectionException;
use App\Exceptions\EHealth\EHealthException;
use App\Jobs\DiagnosticReportSync;
use App\Livewire\DiagnosticReport\Forms\DiagnosticReportCancellationForm as Form;
use App\Models\LegalEntity;
use App\Models\MedicalEvents\Sql\DiagnosticReport;
use App\Models\MedicalEvents\Sql\Episode;
use App\Repositories\MedicalEvents\Repository;
use App\Services\MedicalEvents\Fhir;
use App\Services\MedicalEvents\FhirResource;
use App\Traits\BatchLegalEntityQueries;
use App\Traits\HandlesSyncBatch;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\WithFileUploads;
use Livewire\WithPagination;
use Throwable;

class PatientDiagnosticReports extends BasePatientComponent
{
    use BatchLegalEntityQueries;
    use HandlesSyncBatch;
    use WithFileUploads;
    use WithPagination;

    public Form $form;

    public bool $showCancellationModal = false;

    public bool $showSignatureModal = false;

    public ?int $cancellingDiagnosticReportId = null;

    public array $diagnosticReports = [];

    public array $filterCodeOptions = [];

    public array $filterEncounterOptions = [];

    public array $filterEpisodeOptions = [];

    public array $filterBasedOnOptions = [];

    public array $filterSpecimenOptions = [];

    public string $filterCategory = '';

    public string $filterCode = '';

    public string $filterEncounterId = '';

    public string $filterContextEpisodeId = '';

    public string $filterOriginEpisodeId = '';

    public string $filterBasedOn = '';

    public string $filterSpecimenId = '';

    public string $filterIssuedFrom = '';

    public string $filterIssuedTo = '';

    public bool $showAdditionalParams = false;

    public int $totalEntries = 0;

    public string $syncStatus = '';

    public int $pageSize = 10;

    protected array $dictionaryNames = [
        'eHealth/diagnostic_report_categories',
        'eHealth/cancellation_reasons',
    ];

    protected function getSyncStatus(string $entityType): ?string
    {
        return $this->syncStatus ?: null;
    }

    protected function getBatchName(string $entityType): string
    {
        return DiagnosticReportSync::BATCH_NAME;
    }

    protected function getJobClass(string $entityType): string
    {
        return DiagnosticReportSync::class;
    }

    protected function getEntityConstant(string $entityType): string
    {
        return LegalEntity::ENTITY_DIAGNOSTIC_REPORT;
    }

    protected function onSyncStatusChanged(string $entityType, JobStatus $status): void
    {
        $this->syncStatus = $status->value;
    }

    public function initializeComponent(): void
    {
        $this->getDictionary();

        $this->dictionaries['custom/services'] = dictionary()->services()->flattened()->toArray();

        $this->loadFilters();

        $this->loadDiagnosticReportsFromDb();
    }

    public function search(): void
    {
        $this->validate($this->filterValidationRules());

        $this->resetPage();

        $this->loadDiagnosticReports($this->buildSearchParams());
    }

    public function resetFilters(): void
    {
        $this->reset([
            'filterCategory',
            'filterCode',
            'filterEncounterId',
            'filterContextEpisodeId',
            'filterOriginEpisodeId',
            'filterIssuedFrom',
            'filterIssuedTo',
            'filterBasedOn',
            'filterSpecimenId',
        ]);

        $this->resetPage();

        $this->loadDiagnosticReports($this->buildSearchParams());
    }

    public function sync(): void
    {
        if ($this->cannotStartSync('diagnostic_report')) {
            return;
        }

        if ($this->shouldResumeSync('diagnostic_report')) {
            $this->handleResumeLogic('diagnostic_report');

            return;
        }

        try {
            $response = EHealth::diagnosticReport()->getBySearchParams(
                $this->uuid,
                $this->buildSearchParams(),
            );
        } catch (EHealthException|EHealthConnectionException $exception) {
            $exception->handle('Error while synchronizing diagnostic report');

            return;
        }

        try {
            $validatedData = $response->validate();
            Repository::diagnosticReport()->sync($this->patient(), $validatedData);
        } catch (Throwable $exception) {
            $this->handleDatabaseErrors($exception, 'Error while synchronizing diagnostic report');

            return;
        }

        if ($response->isNotLast()) {
            $this->dispatchRemainingPages('diagnostic_report');
        } else {
            legalEntity()->setEntityStatus(JobStatus::COMPLETED, LegalEntity::ENTITY_DIAGNOSTIC_REPORT);
            Session::flash('success', __('patients.messages.diagnostic_reports_synced_successfully'));
        }

        $this->loadDiagnosticReportsFromDb();

        $this->loadEpisodes();
        $this->loadEncounters();
    }

    public function openDiagnosticReportCancellation(int $diagnosticReportId): void
    {
        $diagnosticReport = Repository::diagnosticReport()->findById($diagnosticReportId);

        if ($message = $this->getCancellationForbiddenMessage($diagnosticReport)) {
            Session::flash('error', $message);

            return;
        }

        $this->resetCancellationState();

        $this->cancellingDiagnosticReportId = $diagnosticReport->id;
        $this->showCancellationModal = true;
    }

    public function closeDiagnosticReportCancellationModal(): void
    {
        $this->resetCancellationState();
    }

    public function proceedToSignature(): void
    {
        if ($this->cancellingDiagnosticReportId === null) {
            Session::flash('error', __('patients.messages.diagnostic_report_not_found'));

            return;
        }

        $diagnosticReport = Repository::diagnosticReport()->findById($this->cancellingDiagnosticReportId);

        if ($message = $this->getCancellationForbiddenMessage($diagnosticReport)) {
            $this->resetCancellationState();
            Session::flash('error', $message);

            return;
        }

        $this->form->explanatoryLetter = filled($this->form->explanatoryLetter)
            ? $this->form->explanatoryLetter
            : null;

        try {
            $this->form->validate($this->form->cancellationRules());
        } catch (ValidationException $exception) {
            $this->showCancellationModal = true;
            $this->showSignatureModal = false;

            Session::flash('error', $exception->validator->errors()->first());
            $this->setErrorBag($exception->validator->getMessageBag());

            return;
        }

        $this->showCancellationModal = false;
        $this->showSignatureModal = true;
    }

    public function cancelSelectedDiagnosticReport(): void
    {
        if ($this->cancellingDiagnosticReportId === null) {
            Session::flash('error', __('patients.messages.diagnostic_report_not_found'));

            return;
        }

        try {
            $validated = $this->form->validate([
                ...$this->form->cancellationRules(),
                ...$this->form->signingRules(),
            ]);
        } catch (ValidationException $exception) {
            Session::flash('error', $exception->validator->errors()->first());
            $this->setErrorBag($exception->validator->getMessageBag());

            return;
        }

        $diagnosticReport = Repository::diagnosticReport()->findById($this->cancellingDiagnosticReportId);

        if ($message = $this->getCancellationForbiddenMessage($diagnosticReport)) {
            $this->showSignatureModal = false;
            Session::flash('error', $message);

            return;
        }

        $explanatoryLetter = $validated['explanatoryLetter'] ?? null;

        try {
            $signedPayload = $this->buildCancellationPackage(
                $diagnosticReport,
                $validated['cancellationReason'],
                $explanatoryLetter
            );
        } catch (EHealthException|EHealthConnectionException $exception) {
            $exception->handle(
                'Error while building diagnostic report cancellation package',
                __('patients.messages.diagnostic_report_cancel_package_prepare_error')
            );

            return;
        }

        try {
            $signedContent = new CipherRequest()->signData(
                $signedPayload,
                $validated['knedp'],
                $validated['keyContainerUpload'],
                $validated['password'],
                Auth::user()->party->taxId
            );
        } catch (CipherException|CipherConnectionException $exception) {
            $exception->handle(
                'Error while signing diagnostic report cancellation package',
                __('patients.messages.diagnostic_report_cancel_package_sign_error')
            );

            return;
        } finally {
            $this->form->resetSigningFields();
        }

        $cancellationReason = FhirResource::make()
            ->coding('eHealth/cancellation_reasons', $validated['cancellationReason'])
            ->toCodeableConcept(
                data_get($this->dictionaries, 'eHealth/cancellation_reasons.' . $validated['cancellationReason'], '')
            );

        try {
            EHealth::diagnosticReport()->cancel($this->uuid, [
                'signed_data' => $signedContent->getBase64Data(),
                'signed_data_encoding' => 'base64',
            ]);

            Repository::diagnosticReport()->markAsEnteredInError(
                $diagnosticReport,
                $cancellationReason,
                $explanatoryLetter
            );
        } catch (EHealthException|EHealthConnectionException $exception) {
            $exception->handle(
                'Error while sending diagnostic report cancellation package',
                __('patients.messages.diagnostic_report_cancel_package_request_error')
            );

            return;
        } catch (Throwable $exception) {
            $this->handleDatabaseErrors(
                $exception,
                'Error while saving diagnostic report cancellation status',
                __('patients.messages.diagnostic_report_cancel_package_save_error')
            );

            return;
        }

        $this->resetCancellationState();
        $this->loadDiagnosticReportsFromDb();

        Session::flash('success', __('patients.messages.diagnostic_report_cancel_request_sent'));
    }

    private function getCancellationForbiddenMessage(DiagnosticReport $diagnosticReport): ?string
    {
        if ($diagnosticReport->status === DiagnosticReportStatus::ENTERED_IN_ERROR) {
            return __('patients.messages.diagnostic_report_already_entered_in_error');
        }

        if ($diagnosticReport->status !== DiagnosticReportStatus::FINAL) {
            return __('patients.messages.only_final_diagnostic_report_can_be_cancelled');
        }

        $currentEmployeeUuid = Auth::user()?->getDiagnosticReportWriterEmployee()?->uuid;

        if (!$currentEmployeeUuid || $diagnosticReport->recordedBy?->value !== $currentEmployeeUuid) {
            return __('patients.messages.diagnostic_report_created_by_another_employee_cannot_be_cancelled');
        }

        if ($diagnosticReport->encounter_id !== null) {
            return __('patients.messages.diagnostic_report_with_encounter_cannot_be_cancelled');
        }

        if (Auth::user()?->cannot('cancel', $diagnosticReport)) {
            return __('patients.policy.cancel_diagnostic_report');
        }

        return null;
    }

    private function buildCancellationPackage(
        DiagnosticReport $diagnosticReport,
        string $cancellationReason,
        ?string $explanatoryLetter
    ): array {
        try {
            $reportRaw = EHealth::diagnosticReport()
                ->getById($this->uuid, $diagnosticReport->uuid)
                ->getData();

            $observationsRaw = $this->loadObservationRawData($diagnosticReport->uuid, onlyActive: true);
        } catch (EHealthException|EHealthConnectionException $exception) {
            report($exception);

            $observationsRaw = collect(Repository::observation()->getByDiagnosticReportId($diagnosticReport->id))
                ->filter(static fn (array $observation): bool => data_get($observation, 'status') !== ObservationStatus::ENTERED_IN_ERROR->value)
                ->values()
                ->toArray();
        }

        return Fhir::diagnosticReport()->toCancellationPackage(
            $reportRaw,
            $observationsRaw,
            $cancellationReason,
            $explanatoryLetter,
            data_get($this->dictionaries, 'eHealth/cancellation_reasons.' . $cancellationReason)
        );
    }

    private function loadObservationRawData(string $diagnosticReportUuid, bool $onlyActive = false): array
    {
        $page = 1;
        $observations = [];

        do {
            $response = EHealth::observation()->getBySearchParams($this->uuid, [
                'diagnostic_report_id' => $diagnosticReportUuid,
                'page' => $page,
            ]);

            $pageData = collect($response->getData());

            if ($onlyActive) {
                $pageData = $pageData->filter(
                    static fn (array $observation): bool => data_get($observation, 'status') !== ObservationStatus::ENTERED_IN_ERROR->value
                );
            }

            $observations = [
                ...$observations,
                ...$pageData->values()->toArray(),
            ];

            $page++;
        } while ($response->isNotLast());

        return $observations;
    }

    private function resetCancellationState(): void
    {
        $this->showCancellationModal = false;
        $this->showSignatureModal = false;
        $this->cancellingDiagnosticReportId = null;
        $this->form->resetCancellationFields();

        if (isset($this->form->knedp)) {
            $this->form->knedp = '';
        }

        if (isset($this->form->password)) {
            $this->form->password = '';
        }

        $this->resetErrorBag();
        $this->resetValidation();
    }

    public function updatedPage(): void
    {
        $this->loadDiagnosticReports($this->buildSearchParams());
    }

    private function loadFilters(): void
    {
        $this->loadServices();

        $this->loadEpisodesFromDb();

        $this->loadEncountersFromDb();
    }

    private function loadDiagnosticReports(array $params = []): void
    {
        try {
            $response = EHealth::diagnosticReport()->getBySearchParams($this->uuid, $params);

            $validateData = $response->validate();

            $paging = $response->getPaging();
            $this->totalEntries = $paging['total_entries'] ?? 0;
            $this->pageSize = $paging['page_size'] ?? 10;

            $this->diagnosticReports = Arr::toCamelCase($validateData);
        } catch (EHealthException|EHealthConnectionException $exception) {
            $this->diagnosticReports = [];

            $exception->handle('Error while loading diagnostic reports');
        }
    }

    private function loadDiagnosticReportsFromDb(): void
    {
        $diagnosticReports = DiagnosticReport::withAllRelations()
            ->forPatient($this->patient())
            ->get();

        $this->totalEntries = $diagnosticReports->count();

        $this->diagnosticReports = $diagnosticReports
            ->map(function (DiagnosticReport $diagnosticReport) {
                $data = Arr::toCamelCase($diagnosticReport->toArray());
                $data['id'] = $diagnosticReport->id;

                return $data;
            })
            ->toArray();

        $this->diagnosticReports = $this->formatDatesForDisplay($this->diagnosticReports);
    }

    private function loadEpisodes(): void
    {
        try {
            $response = EHealth::episode()->getBySearchParams(
                $this->uuid,
                ['managing_organization_id' => legalEntity()?->uuid]
            );

            $validatedData = $response->validate();

            $this->filterEpisodeOptions = collect($validatedData)
                ->map(function (array $episode) {
                    $episodeId = data_get($episode, 'uuid');

                    if (!$episodeId) {
                        return null;
                    }

                    return [
                        'value' => $episodeId,
                        'label' => data_get($episode, 'name') ?: $episodeId,
                        'description' => $episodeId,
                    ];
                })
                ->filter()
                ->unique('value')
                ->sortBy('label')
                ->values()
                ->toArray();
        } catch (EHealthException|EHealthConnectionException $exception) {
            $this->filterEpisodeOptions = [];

            $exception->handle('Error while loading episodes');
        }
    }

    private function loadEpisodesFromDb(): void
    {
        $filterEpisodeOptions = Episode::forPatient($this->patient())->get()->toArray();

        $this->totalEntries = count($filterEpisodeOptions);

        $this->filterEpisodeOptions = collect($filterEpisodeOptions)
            ->map(function (array $episode) {
                $episodeId = data_get($episode, 'uuid');

                if (!$episodeId) {
                    return null;
                }

                return [
                    'value' => $episodeId,
                    'label' => data_get($episode, 'name') ?: $episodeId,
                    'description' => $episodeId,
                ];
            })
            ->filter()
            ->unique('value')
            ->values()
            ->toArray();
    }

    private function loadEncounters(): void
    {
        try {
            $response = EHealth::encounter()->getBySearchParams(
                $this->uuid,
                [
                    'managing_organization_id' => legalEntity()?->uuid,
                    'page_size' => 100,
                ]
            );

            $validatedData = $response->validate();

            $this->filterEncounterOptions = Arr::toCamelCase($validatedData);
        } catch (EHealthException|EHealthConnectionException $exception) {
            $this->filterEncounterOptions = [];

            $exception->handle('Error while loading encounters');
        }
    }

    private function loadEncountersFromDb(): void
    {
        $this->filterEncounterOptions = Arr::toCamelCase(
            $this->formatDatesForDisplay(
                Repository::encounter()->getByPersonId($this->patient())
            )
        );
    }

    private function loadServices(): void
    {
        $this->filterCodeOptions = collect(dictionary()->services()->flattened()->toArray())
            ->map(function (array $service) {
                $serviceId = data_get($service, 'id');

                if (!$serviceId) {
                    return null;
                }

                $serviceCode = data_get($service, 'code');
                $serviceName = data_get($service, 'name') ?: $serviceId;

                return [
                    'value' => $serviceId,
                    'label' => $serviceCode
                        ? $serviceCode . ' | ' . $serviceName
                        : $serviceName,
                    'description' => $serviceId,
                ];
            })
            ->filter()
            ->unique('value')
            ->sortBy('label')
            ->values()
            ->toArray();
    }

    private function buildSearchParams(): array
    {
        return array_filter([
            'code' => $this->filterCode ?: null,
            'encounter_id' => $this->filterEncounterId ?: null,
            'context_episode_id' => $this->filterContextEpisodeId ?: null,
            'origin_episode_id' => $this->filterOriginEpisodeId ?: null,
            'issued_from' => $this->filterIssuedFrom ?: null,
            'issued_to' => $this->filterIssuedTo ?: null,
            'based_on' => $this->filterBasedOn ?: null,
            'managing_organization_id' => legalEntity()?->uuid,
            'specimen_id' => $this->filterSpecimenId ?: null,
            'page' => $this->getPage(),
            'page_size' => $this->pageSize,
        ], static fn ($value) => $value !== null && $value !== '');
    }

    private function buildPaginator(): LengthAwarePaginator
    {
        return new LengthAwarePaginator(
            $this->diagnosticReports,
            $this->totalEntries,
            $this->pageSize,
            $this->getPage(),
            ['path' => request()->url()]
        );
    }

    protected function filterValidationRules(): array
    {
        return [
            'filterCategory' => ['nullable', 'string', 'max:255'],
            'filterCode' => ['nullable', 'uuid'],
            'filterEncounterId' => ['nullable', 'uuid'],
            'filterContextEpisodeId' => ['nullable', 'uuid'],
            'filterOriginEpisodeId' => ['nullable', 'uuid'],
            'filterIssuedFrom' => ['nullable', 'date_format:' . config('app.date_format')],
            'filterIssuedTo' => ['nullable', 'date_format:' . config('app.date_format')],
            'filterBasedOn' => ['nullable', 'uuid'],
            'filterSpecimenId' => ['nullable', 'uuid'],
        ];
    }

    public function render(): View
    {
        return view('livewire.person.records.diagnostic-reports', [
            'paginatedDiagnosticReports' => $this->buildPaginator(),
        ]);
    }
}
