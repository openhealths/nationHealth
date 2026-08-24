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
use App\Models\MedicalEvents\Sql\Identifier;
use App\Repositories\MedicalEvents\Repository;
use App\Services\MedicalEvents\Fhir;
use App\Services\MedicalEvents\FhirResource;
use App\Traits\BatchLegalEntityQueries;
use App\Traits\HandlesSyncBatch;
use Illuminate\Support\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\ValidationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
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

    public array $services = [];

    public array $encounters = [];

    public array $episodes = [];

    public array $basedOnRequests = [];

    public array $specimens = [];

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

    public string $syncStatus = '';

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

    protected function initializeComponent(): void
    {
        $this->getDictionary();

        $this->syncStatus = legalEntity()->getEntityStatus(LegalEntity::ENTITY_DIAGNOSTIC_REPORT) ?? '';

        $this->loadFilterOptions();
    }

    /**
     * Narrow the service list down to the picked category and drop the service that no longer belongs to it.
     *
     * @return void
     */
    public function updatedFilterCategory(): void
    {
        $this->filterCode = '';

        $this->loadServices();
    }

    /**
     * Diagnostic reports for the current page, either from the eHealth search or from the local database.
     *
     * @return LengthAwarePaginator
     */
    #[Computed]
    public function paginatedDiagnosticReports(): LengthAwarePaginator
    {
        return $this->isSearching
            ? $this->searchDiagnosticReportsFromEHealth()
            : $this->paginateLocalDiagnosticReports();
    }

    public function search(): void
    {
        $this->validate($this->filterValidationRules());

        $this->isSearching = true;
        $this->resetPage();
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
            'isSearching'
        ]);

        $this->loadServices();

        $this->resetPage();
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
                ['managing_organization_id' => legalEntity()->uuid]
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

        $this->loadFilterOptions();

        $this->isSearching = false;
        $this->resetPage();
    }

    public function openDiagnosticReportView(string $diagnosticReportUuid): void
    {
        $diagnosticReport = $this->findDiagnosticReportForAction($diagnosticReportUuid);

        if (!$diagnosticReport) {
            return;
        }

        if ($this->prepersonId !== null) {
            $this->redirectRoute(
                'prepersons.diagnostic-report.view',
                [
                    legalEntity(),
                    'preperson' => $this->prepersonId,
                    'diagnosticReportId' => $diagnosticReport->id,
                ],
                navigate: true
            );

            return;
        }

        $this->redirectRoute(
            'diagnostic-report.view',
            [
                legalEntity(),
                'person' => $this->personId,
                'diagnosticReportId' => $diagnosticReport->id,
            ],
            navigate: true
        );
    }

    public function openDiagnosticReportCancellation(string $diagnosticReportUuid): void
    {
        $diagnosticReport = $this->findDiagnosticReportForAction($diagnosticReportUuid);

        if (!$diagnosticReport) {
            return;
        }

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
            $signedContent = (new CipherRequest())->signData(
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

        Session::flash('success', __('patients.messages.diagnostic_report_cancel_request_sent'));
    }

    private function findDiagnosticReportForAction(string $diagnosticReportUuid): ?DiagnosticReport
    {
        if (blank($diagnosticReportUuid)) {
            Session::flash('error', __('patients.messages.diagnostic_report_not_found'));

            return null;
        }

        try {
            return Repository::diagnosticReport()->findByUuid($diagnosticReportUuid);
        } catch (ModelNotFoundException) {
            Session::flash('error', __('patients.messages.diagnostic_report_not_found_in_db'));

            return null;
        }
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
        $reportRaw = EHealth::diagnosticReport()
            ->getById($this->uuid, $diagnosticReport->uuid)
            ->getData();

        try {
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

    /**
     * Paginate locally stored (synced) diagnostic reports straight from the database.
     *
     * @return LengthAwarePaginator
     */
    protected function paginateLocalDiagnosticReports(): LengthAwarePaginator
    {
        $paginator = DiagnosticReport::forPatient($this->patient())
            ->withAllRelations()
            ->recentlyUpdatedFirst()
            ->paginate(config('pagination.per_page'));

        $paginator->setCollection(
            collect($this->formatDatesForDisplay(Arr::toCamelCase($paginator->getCollection()->toArray())))
        );

        return $paginator;
    }

    /**
     * Fetch a single page of diagnostic reports from the eHealth API for the active search filters.
     *
     * @return LengthAwarePaginator
     */
    protected function searchDiagnosticReportsFromEHealth(): LengthAwarePaginator
    {
        $perPage = config('pagination.per_page');
        $page = $this->getPage();

        try {
            $response = EHealth::diagnosticReport()->getBySearchParams($this->uuid, $this->buildSearchParams());
            $diagnosticReports = Arr::toCamelCase($response->validate());
            $total = $response->getPaging()['total_entries'];
        } catch (EHealthException|EHealthConnectionException $exception) {
            $exception->handle('Error while loading diagnostic reports');
            $diagnosticReports = [];
            $total = 0;
        }

        return new LengthAwarePaginator(collect($diagnosticReports), $total, $perPage, $page, [
            'path' => LengthAwarePaginator::resolveCurrentPath()
        ]);
    }

    /**
     * Load the dropdown options the user can filter diagnostic reports by.
     *
     * @return void
     */
    private function loadFilterOptions(): void
    {
        $this->loadServices();

        $this->episodes = Repository::episode()->getByPersonId($this->patient());

        $this->encounters = Repository::encounter()->getByPersonId($this->patient());

        $this->loadBasedOnRequestsFromDb();
    }

    /**
     * Build the service request options out of the referrals the stored diagnostic reports were based on.
     *
     * @return void
     */
    private function loadBasedOnRequestsFromDb(): void
    {
        $this->basedOnRequests = DiagnosticReport::forPatient($this->patient())
            ->with('basedOn')
            ->get()
            ->pluck('basedOn')
            ->filter(static fn (?Identifier $basedOn): bool => (bool) $basedOn?->value)
            ->map(static fn (Identifier $basedOn): array => [
                'uuid' => $basedOn->value,
                'name' => $basedOn->displayValue ?: $basedOn->value
            ])
            ->unique('uuid')
            ->values()
            ->toArray();
    }

    /**
     * Build the service options for the code combobox, limited to the picked category when there is one.
     *
     * @return void
     */
    private function loadServices(): void
    {
        $this->services = collect(dictionary()->services()->flattened()->toArray())
            ->when(
                $this->filterCategory !== '',
                fn (Collection $services): Collection => $services->where('category', $this->filterCategory)
            )
            ->map(static function (array $service): ?array {
                $serviceId = data_get($service, 'id');

                if (!$serviceId) {
                    return null;
                }

                $serviceCode = data_get($service, 'code');
                $serviceName = data_get($service, 'name') ?: $serviceId;

                return [
                    'id' => $serviceId,
                    'name' => $serviceCode
                        ? $serviceCode . ' | ' . $serviceName
                        : $serviceName
                ];
            })
            ->filter()
            ->unique('id')
            ->sortBy('name')
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
            'managing_organization_id' => legalEntity()->uuid,
            'specimen_id' => $this->filterSpecimenId ?: null,
            'page' => $this->getPage(),
            'page_size' => config('pagination.per_page')
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
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
        return view('livewire.person.records.diagnostic-reports');
    }
}
