<?php

declare(strict_types=1);

namespace App\Livewire\Person\Records;

use App\Classes\eHealth\EHealth;
use App\Core\Arr;
use App\Enums\JobStatus;
use App\Enums\Person\ProcedureStatus;
use App\Exceptions\EHealth\EHealthConnectionException;
use App\Exceptions\EHealth\EHealthException;
use App\Jobs\ProcedureSync;
use App\Models\Equipment;
use App\Models\LegalEntity;
use App\Models\MedicalEvents\Sql\Identifier;
use App\Models\MedicalEvents\Sql\Procedure;
use App\Repositories\MedicalEvents\Repository;
use App\Traits\BatchLegalEntityQueries;
use App\Traits\HandlesSyncBatch;
use App\Classes\Cipher\Api\CipherRequest;
use App\Exceptions\Cipher\CipherConnectionException;
use App\Exceptions\Cipher\CipherException;
use App\Livewire\Procedure\Forms\ProcedureCancellationForm as Form;
use App\Services\MedicalEvents\Fhir;
use App\Services\MedicalEvents\FhirResource;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\WithPagination;
use Livewire\WithFileUploads;
use Throwable;

class PatientProcedures extends BasePatientComponent
{
    use BatchLegalEntityQueries;
    use HandlesSyncBatch;
    use WithPagination;
    use WithFileUploads;

    public Form $form;

    public bool $showCancellationModal = false;

    public bool $showSignatureModal = false;

    public ?string $procedureUuid = null;

    public bool $showAdditionalParams = false;

    public array $episodes = [];

    public array $usedReferences = [];

    public array $basedOnRequests = [];

    public array $services = [];

    public array $encounters = [];

    public array $originEpisodes = [];

    /**
     * Stays empty until devices are supported in the project.
     *
     * @var array
     */
    public array $devices = [];

    public string $filterCategory = '';

    public string $filterStatus = '';

    public string $filterEpisodeId = '';

    public string $filterUsedReferenceId = '';

    public string $filterBasedOn = '';

    public string $filterCode = '';

    public string $filterEncounterId = '';

    public string $filterOriginEpisodeId = '';

    public string $filterDeviceId = '';

    public string $syncStatus = '';

    protected array $dictionaryNames = [
        'eHealth/procedure_status_reasons',
        'eHealth/procedure_categories',
        'eHealth/procedure_outcomes',
        'eHealth/report_origins',
        'eHealth/assistive_products',
    ];

    protected function getSyncStatus(string $entityType): ?string
    {
        return $this->syncStatus ?: null;
    }

    protected function getBatchName(string $entityType): string
    {
        return ProcedureSync::BATCH_NAME;
    }

    protected function getJobClass(string $entityType): string
    {
        return ProcedureSync::class;
    }

    protected function getEntityConstant(string $entityType): string
    {
        return LegalEntity::ENTITY_PROCEDURE;
    }

    protected function onSyncStatusChanged(string $entityType, JobStatus $status): void
    {
        $this->syncStatus = $status->value;
    }

    protected function initializeComponent(): void
    {
        $this->getDictionary();

        $this->syncStatus = legalEntity()->getEntityStatus(LegalEntity::ENTITY_PROCEDURE) ?? '';

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

        $this->getServices();
    }

    /**
     * Procedures for the current page, either from the eHealth search or from the local database.
     *
     * @return LengthAwarePaginator
     */
    #[Computed]
    public function paginatedProcedures(): LengthAwarePaginator
    {
        return $this->isSearching
            ? $this->searchProceduresFromEHealth()
            : $this->paginateLocalProcedures();
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
            'filterStatus',
            'filterEpisodeId',
            'filterUsedReferenceId',
            'filterBasedOn',
            'filterCode',
            'filterEncounterId',
            'filterOriginEpisodeId',
            'filterDeviceId',
            'isSearching'
        ]);

        $this->getServices();

        $this->resetPage();
    }

    public function sync(): void
    {
        if ($this->cannotStartSync('procedure')) {
            return;
        }

        if ($this->shouldResumeSync('procedure')) {
            $this->handleResumeLogic('procedure');

            return;
        }

        try {
            $response = EHealth::procedure()->getBySearchParams(
                $this->uuid,
                ['managing_organization_id' => legalEntity()->uuid]
            );
        } catch (EHealthException|EHealthConnectionException $exception) {
            $exception->handle('Error while synchronizing procedure');

            return;
        }

        try {
            $validatedData = $response->validate();
            Repository::procedure()->sync($this->patient(), $validatedData);
        } catch (Throwable $exception) {
            $this->handleDatabaseErrors($exception, 'Error while synchronizing procedure');

            return;
        }

        if ($response->isNotLast()) {
            $this->dispatchRemainingPages('procedure');
        } else {
            legalEntity()->setEntityStatus(JobStatus::COMPLETED, LegalEntity::ENTITY_PROCEDURE);
            Session::flash('success', __('patients.messages.procedure_synced_successfully'));
        }

        $this->loadFilterOptions();

        $this->isSearching = false;
        $this->resetPage();
    }

    public function openProcedureView(string $procedureUuid): void
    {
        $procedure = $this->findProcedureForAction($procedureUuid);

        if (!$procedure) {
            return;
        }

        if ($this->prepersonId !== null) {
            $this->redirectRoute(
                'prepersons.procedure.view',
                [legalEntity(), 'preperson' => $this->prepersonId, 'procedureId' => $procedure->id],
                navigate: true
            );

            return;
        }

        $this->redirectRoute(
            'procedure.view',
            [legalEntity(), 'person' => $this->personId, 'procedureId' => $procedure->id],
            navigate: true
        );
    }

    public function openProcedureCancellation(string $procedureUuid): void
    {
        $procedure = $this->findProcedureForAction($procedureUuid);

        if (!$procedure) {
            return;
        }

        if ($message = $this->getCancellationForbiddenMessage($procedure)) {
            Session::flash('error', $message);

            return;
        }

        $this->resetCancellationState();

        $this->procedureUuid = $procedure->uuid;
        $this->showCancellationModal = true;
    }

    public function closeProcedureCancellationModal(): void
    {
        $this->resetCancellationState();
    }

    public function proceedToSignature(): void
    {
        if ($this->procedureUuid === null) {
            Session::flash('error', __('patients.messages.procedure_not_found'));

            return;
        }

        $procedure = Repository::procedure()->findByUuid($this->procedureUuid);

        if ($message = $this->getCancellationForbiddenMessage($procedure)) {
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

    public function cancelSelectedProcedure(): void
    {
        if ($this->procedureUuid === null) {
            Session::flash('error', __('patients.messages.procedure_not_found'));

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

        $procedure = Repository::procedure()->findByUuid($this->procedureUuid);

        if ($message = $this->getCancellationForbiddenMessage($procedure)) {
            $this->showSignatureModal = false;
            Session::flash('error', $message);

            return;
        }

        $explanatoryLetter = $validated['explanatoryLetter'] ?? null;

        try {
            $signedPayload = $this->buildCancellationPackage(
                $procedure,
                $validated['statusReason'],
                $explanatoryLetter
            );
        } catch (EHealthException|EHealthConnectionException $exception) {
            $exception->handle(
                'Error while building procedure cancellation package',
                __('patients.messages.procedure_cancel_package_prepare_error')
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
                'Error while signing procedure cancellation package',
                __('patients.messages.procedure_cancel_package_sign_error')
            );

            return;
        } finally {
            $this->form->resetSigningFields();
        }

        $statusReason = FhirResource::make()
            ->coding('eHealth/procedure_status_reasons', $validated['statusReason'])
            ->toCodeableConcept(
                data_get($this->dictionaries, 'eHealth/procedure_status_reasons.' . $validated['statusReason'], '')
            );

        try {
            EHealth::procedure()->cancel($this->uuid, $procedure->uuid, [
                'signed_data' => $signedContent->getBase64Data(),
                'signed_data_encoding' => 'base64',
            ]);

            Repository::procedure()->markAsEnteredInError(
                $procedure,
                $statusReason,
                $explanatoryLetter
            );
        } catch (EHealthException|EHealthConnectionException $exception) {
            $exception->handle(
                'Error while sending procedure cancellation package',
                __('patients.messages.procedure_cancel_package_request_error')
            );

            return;
        } catch (Throwable $exception) {
            $this->handleDatabaseErrors(
                $exception,
                'Error while saving procedure cancellation status',
                __('patients.messages.procedure_cancel_package_save_error')
            );

            return;
        }

        $this->resetCancellationState();

        Session::flash('success', __('patients.messages.procedure_cancel_request_sent'));
    }

    private function findProcedureForAction(string $procedureUuid): ?Procedure
    {
        if (blank($procedureUuid)) {
            Session::flash('error', __('patients.messages.procedure_not_found'));

            return null;
        }

        try {
            return Repository::procedure()->findByUuid($procedureUuid);
        } catch (ModelNotFoundException) {
            Session::flash('error', __('patients.messages.procedure_not_found_in_db'));

            return null;
        }
    }

    private function getCancellationForbiddenMessage(Procedure $procedure): ?string
    {
        if ($procedure->status === ProcedureStatus::ENTERED_IN_ERROR->value) {
            return __('patients.messages.procedure_already_entered_in_error');
        }

        if ($procedure->encounter_id !== null) {
            return __('patients.messages.procedure_with_encounter_cannot_be_cancelled_separately');
        }

        if (Auth::user()?->cannot('cancel', $procedure)) {
            return __('patients.policy.cancel_procedure');
        }

        return null;
    }

    private function buildCancellationPackage(
        Procedure $procedure,
        string $statusReason,
        ?string $explanatoryLetter
    ): array {
        $procedureRaw = EHealth::procedure()
            ->getById($this->uuid, $procedure->uuid)
            ->getData();

        return Fhir::procedure()->toCancellationPackage(
            $procedureRaw,
            $statusReason,
            $explanatoryLetter,
            data_get($this->dictionaries, 'eHealth/procedure_status_reasons.' . $statusReason)
        );
    }

    private function resetCancellationState(): void
    {
        $this->showCancellationModal = false;
        $this->showSignatureModal = false;
        $this->procedureUuid = null;
        $this->form->resetCancellationFields();
        $this->form->resetSigningFields();

        $this->resetErrorBag();
        $this->resetValidation();
    }

    /**
     * Paginate locally stored (synced) procedures straight from the database.
     *
     * @return LengthAwarePaginator
     */
    protected function paginateLocalProcedures(): LengthAwarePaginator
    {
        $paginator = Procedure::forPatient($this->patient())
            ->withAllRelations()
            ->latest()
            ->paginate(config('pagination.per_page'));

        $paginator->setCollection(
            collect($this->formatDatesForDisplay(
                $paginator
                    ->getCollection()
                    ->map(static function (Procedure $procedure): array {
                        $data = Arr::toCamelCase($procedure->toArray());
                        $data['createdAt'] = $procedure->created_at?->toDateTimeString();

                        return $data;
                    })
                    ->toArray()
            ))
        );

        return $paginator;
    }

    /**
     * Fetch a single page of procedures from the eHealth API for the active search filters.
     *
     * @return LengthAwarePaginator
     */
    protected function searchProceduresFromEHealth(): LengthAwarePaginator
    {
        $perPage = config('pagination.per_page');
        $page = $this->getPage();

        try {
            $response = EHealth::procedure()->getBySearchParams($this->uuid, $this->buildSearchParams());
            $procedures = Arr::toCamelCase($response->validate());
            $total = $response->getPaging()['total_entries'];
        } catch (EHealthException|EHealthConnectionException $exception) {
            $exception->handle('Error while loading procedures');
            $procedures = [];
            $total = 0;
        }

        return new LengthAwarePaginator(collect($procedures), $total, $perPage, $page, [
            'path' => LengthAwarePaginator::resolveCurrentPath()
        ]);
    }

    /**
     * Load the dropdown options the user can filter procedures by.
     *
     * @return void
     */
    private function loadFilterOptions(): void
    {
        $this->getServices();

        $this->episodes = Repository::episode()->getByPersonId($this->patient());
        $this->originEpisodes = $this->episodes;

        $this->encounters = Repository::encounter()->getByPersonId($this->patient());

        $this->getUsedReferencesFromDb();

        $this->getBasedOnFromDb();

        $this->getDevicesFromDb();
    }

    /**
     * Build the service options for the code combobox, limited to the picked category when there is one.
     *
     * @return void
     */
    private function getServices(): void
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

    /**
     * Build the used reference options, labelled by the equipment name when it is known locally.
     *
     * @return void
     */
    private function getUsedReferencesFromDb(): void
    {
        $usedReferences = Procedure::with('usedReferences')
            ->forPatient($this->patient())
            ->get()
            ->flatMap(static fn (Procedure $procedure) => $procedure->usedReferences);

        $equipmentNames = Equipment::with('names')
            ->whereIn('uuid', $usedReferences->pluck('value'))
            ->get()
            ->pluck('names.0.name', 'uuid');

        $this->usedReferences = $usedReferences
            ->filter(static fn (?Identifier $identifier): bool => (bool) $identifier?->value)
            ->map(static fn (Identifier $identifier): array => [
                'uuid' => $identifier->value,
                'name' => ($equipmentNames[$identifier->value] ?? $identifier->displayValue ?? '-')
                    . ' | ' . $identifier->value
            ])
            ->unique('uuid')
            ->sortBy('name')
            ->values()
            ->toArray();
    }

    /**
     * Build the service request options out of the referrals the stored procedures were based on.
     *
     * @return void
     */
    private function getBasedOnFromDb(): void
    {
        $this->basedOnRequests = Procedure::with('basedOn')
            ->forPatient($this->patient())
            ->get()
            ->pluck('basedOn')
            ->filter(static fn (?Identifier $basedOn): bool => (bool) $basedOn?->value)
            ->map(static fn (Identifier $basedOn): array => [
                'uuid' => $basedOn->value,
                'name' => $basedOn->displayValue ?: $basedOn->value
            ])
            ->unique('uuid')
            ->sortBy('name')
            ->values()
            ->toArray();
    }

    private function getDevicesFromDb(): void
    {
        //TODO implement device_id filter options
    }

    private function buildSearchParams(): array
    {
        return array_filter([
            'episode_id' => $this->filterEpisodeId ?: null,
            'status' => $this->filterStatus ?: null,
            'used_reference_id' => $this->filterUsedReferenceId ?: null,
            'based_on' => $this->filterBasedOn ?: null,
            'code' => $this->filterCode ?: null,
            'managing_organization_id' => legalEntity()->uuid,
            'encounter_id' => $this->filterEncounterId ?: null,
            'origin_episode_id' => $this->filterOriginEpisodeId ?: null,
            'device_id' => $this->filterDeviceId ?: null,
            'page' => $this->getPage(),
            'page_size' => config('pagination.per_page')
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    protected function filterValidationRules(): array
    {
        return [
            'filterCategory' => ['nullable', 'string', 'max:255'],
            'filterStatus' => ['nullable', Rule::in(ProcedureStatus::values())],
            'filterEpisodeId' => ['nullable', 'uuid'],
            'filterUsedReferenceId' => ['nullable', 'uuid'],
            'filterBasedOn' => ['nullable', 'uuid'],
            'filterCode' => ['nullable', 'uuid'],
            'filterEncounterId' => ['nullable', 'uuid'],
            'filterOriginEpisodeId' => ['nullable', 'uuid'],
            'filterDeviceId' => ['nullable', 'uuid'],
        ];
    }

    public function render(): View
    {
        return view('livewire.person.records.procedures');
    }
}
