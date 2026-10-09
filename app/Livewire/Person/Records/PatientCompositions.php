<?php

declare(strict_types=1);

namespace App\Livewire\Person\Records;

use App\Classes\eHealth\EHealth;
use App\Enums\MergeRequest\Status as MergeRequestStatus;
use App\Enums\Composition\CompositionAsyncOperation;
use App\Enums\Composition\CompositionCategory;
use App\Enums\Composition\CompositionStatus;
use App\Enums\Composition\CompositionType;
use App\Exceptions\EHealth\EHealthConnectionException;
use App\Exceptions\EHealth\EHealthException;
use App\Livewire\Composition\Forms\CompositionCancellationForm;
use App\Models\MedicalEvents\Sql\Composition;
use App\Models\MedicalEvents\Sql\CompositionOperation;
use App\Repositories\MedicalEvents\CompositionOperationRepository;
use App\Models\MergeRequest;
use App\Models\Preperson;
use App\Enums\Composition\CompositionJobStatus;
use App\Repositories\MedicalEvents\Repository;
use App\Services\SignatureService;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\WithFileUploads;
use Livewire\WithPagination;
use Throwable;

/**
 * Patient Compositions list component — displays МВН and МВТН for a patient.
 *
 * Supports:
 *  - Listing compositions from local DB (default view)
 *  - Searching compositions via eHealth API searchCompositions
 *  - Viewing composition details and print form
 *  - Cancelling a composition (entered in error) with ЕП signing
 *  - Re-sending a МВТН to ERLN on integration failure
 *
 * ТЗ references: 3.8.1.3, 3.8.1.9, 3.8.1.10, 3.8.2.3, 3.8.2.11, 3.8.2.14, 3.8.2.15
 */
class PatientCompositions extends BasePatientComponent
{
    use \App\Livewire\Composition\Concerns\InteractsWithCompositions;
    use WithFileUploads;
    use WithPagination;

    public CompositionCancellationForm $form;

    /**
     * Whether the KEP signing modal is open.
     *
     * Cancellation always requires a signature, so the shared signature modal doubles as
     * the cancellation dialog and carries the reason field in its custom slot.
     */
    public bool $showSignatureModal = false;

    /** @var string|null UUID of the composition being cancelled */
    #[Locked]
    public ?string $cancellingCompositionUuid = null;

    /** @var bool Whether to show the composition detail modal */
    public bool $showDetailModal = false;

    /** @var string|null UUID of the composition being displayed in detail */
    public ?string $viewingCompositionUuid = null;

    /** @var array|null Full composition data fetched from eHealth for detail view */
    public ?array $compositionDetail = null;

    /** @var array|null Integration status from getIntegrationData */
    public ?array $integrationData = null;

    /** @var string|null HTML print form from eHealth getPrintForm */
    public ?string $printFormHtml = null;

    /** @var bool Whether to show the print form modal */
    public bool $showPrintModal = false;

    public string $filterType = '';

    public string $filterStatus = '';

    public string $filterEncounterId = '';

    public string $filterEpisodeOfCareId = '';

    public string $filterSectionFocusUuid = '';

    /** @var string|null UUID of the composition being re-sent to ERLN */
    #[Locked]
    public ?string $resendingErlnCompositionUuid = null;

    /** @var bool Whether to show the ERLN re-send confirmation modal */
    public bool $showErlnResendModal = false;

    // Lifecycle

    protected function initializeComponent(): void
    {
        // Nothing extra to initialise here; patient data is loaded by BasePatientComponent.
    }

    // Computed properties

    /**
     * Paginated compositions, always read from the local table.
     *
     * Searching refreshes that table from eHealth first rather than returning the raw
     * API payload, so the view never has to deal with two different record shapes.
     */
    #[Computed]
    public function paginatedCompositions(): LengthAwarePaginator
    {
        return $this->paginateLocalCompositions();
    }

    /**
     * All available composition statuses for filter dropdown.
     *
     * @return Collection<int, array{value: string, label: string}>
     */
    #[Computed]
    public function statuses(): Collection
    {
        return collect(CompositionStatus::cases())->map(fn (CompositionStatus $s) => [
            'value' => $s->value,
            'label' => $s->label(),
        ]);
    }

    /**
     * Available composition types for the filter dropdown.
     * Adjust based on the current user's role.
     *
     * @return Collection<int, array{value: string, label: string}>
     */
    #[Computed]
    public function types(): Collection
    {
        return collect(CompositionType::cases())->map(fn (CompositionType $t) => [
            'value' => $t->value,
            'label' => $t->label(),
        ]);
    }

    // Search & filtering

    public function search(): void
    {
        $this->resetPage();
        $this->refreshFromEHealth();
        unset($this->paginatedCompositions);
    }

    public function resetFilters(): void
    {
        $this->reset([
            'filterType',
            'filterStatus',
            'filterEncounterId',
            'filterEpisodeOfCareId',
            'filterSectionFocusUuid',
            'isSearching',
        ]);

        $this->resetPage();
    }

    // Detail view

    /**
     * Show the locally stored composition payload (TV 3.8.1.6.1 / 3.8.2.8.1).
     */
    public function viewComposition(string $compositionUuid): void
    {
        $this->viewingCompositionUuid = $compositionUuid;
        $this->compositionDetail = null;
        $this->integrationData = null;
        $this->printFormHtml = null;

        $composition = $this->findLocalComposition($compositionUuid);

        if (!$composition) {
            Session::flash('error', __('compositions.errors.not_found'));

            return;
        }

        abort_unless(Auth::user()->can('view', $composition), 404);

        $this->compositionDetail = $composition->toDetail();
        $this->showDetailModal = true;
        $this->integrationData = $composition->integrationDetails();
    }

    public function closeDetailModal(): void
    {
        $this->showDetailModal = false;
        $this->viewingCompositionUuid = null;
        $this->compositionDetail = null;
        $this->integrationData = null;
    }

    /**
     * Load print form from eHealth API (getPrintForm).
     * ТЗ 3.8.1.6.2 / 3.8.1.8.2 / 3.8.2.8.3
     *
     * IMPORTANT per ТЗ 3.8.1.1.5.1 / 3.8.2.8.3.1:
     * MIS must NOT add any logos, ads, or other information to this content.
     */
    public function loadPrintForm(string $compositionUuid): void
    {
        $composition = $this->findLocalComposition($compositionUuid);

        if (!$composition?->hasReadContext) {
            Session::flash('error', __('compositions.errors.missing_read_context'));

            return;
        }

        abort_unless(Auth::user()->can('view', $composition), 404);

        try {
            $templateId = $composition->type->printTemplateId();
            $response = EHealth::composition()->getPrintForm(
                $composition->patientUuid,
                $composition->uuid,
                $composition->episodeOfCareUuid,
                $composition->encounterUuid,
                $templateId
            );

            $this->showDetailModal = false;
            $this->printFormHtml = $response->body();
            $this->showPrintModal = true;
        } catch (EHealthConnectionException | EHealthException $exception) {
            Session::flash('error', __('compositions.errors.print_form_failed'));

            Log::error('Failed to load composition print form', [
                'compositionUuid' => $compositionUuid,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    public function closePrintModal(): void
    {
        $this->showPrintModal = false;
        $this->printFormHtml = null;
    }

    // Cancellation (entered in error)

    /**
     * Open the cancellation modal after pre-flight checks.
     * ТЗ 3.8.1.10.1 / 3.8.2.15.1
     */
    public function openCancellationModal(string $compositionUuid): void
    {
        $composition = $this->findLocalComposition($compositionUuid);

        if (!$composition) {
            Session::flash('error', __('compositions.errors.not_found'));

            return;
        }

        // Status and authorship are checked by the policy. The remaining preconditions from
        // TV 3.8.1.10.1 and 3.8.2.15.1 — that no integration process has started, and that
        // the cancellation timeout has not elapsed — are only known to eHealth, which
        // rejects the request itself.
        abort_unless(Auth::user()->can('cancel', $composition), 404);

        // TV 3.8.1.10.1 — a birth conclusion cannot be cancelled once DRACS / DIIA
        // processing has started. The call is skipped for МВТН: those may already
        // have an ERLN record and are cancelled together with it.
        if ($composition->isNewborn && ($this->fetchIntegration($composition) !== [])) {
            Session::flash('error', __('compositions.errors.cancel_has_integration'));

            return;
        }

        $this->cancellingCompositionUuid = $compositionUuid;
        unset($this->cancellationReasons, $this->cancellationWarning);
        $this->form->resetCancellationFields();
        $this->form->resetSigningFields();
        $this->showSignatureModal = true;
    }

    public function closeCancellationModal(): void
    {
        $this->showSignatureModal = false;
        $this->cancellingCompositionUuid = null;
        $this->form->resetCancellationFields();
        $this->form->resetSigningFields();
    }

    /**
     * Where the "new disability conclusion" action leads for this patient.
     *
     * Prepersons live under their own route family, so the link cannot be built from a
     * single named route.
     */
    #[Computed]
    public function createTempDisabilityUrl(): string
    {
        return $this->prepersonId !== null
            ? route('prepersons.compositions.temp-disability.create', [legalEntity(), 'preperson' => $this->prepersonId])
            : route('persons.compositions.temp-disability.create', [legalEntity(), 'person' => $this->personId]);
    }

    /**
     * A birth conclusion is filed against the newborn, so the default entry point is
     * the preperson card. Opening it from the mother's card asks for the child next.
     */
    #[Computed]
    public function createNewbornUrl(): string
    {
        return $this->prepersonId !== null
            ? route('prepersons.compositions.newborn.create', [legalEntity(), 'preperson' => $this->prepersonId])
            : route('persons.compositions.newborn.create', [legalEntity(), 'person' => $this->personId]);
    }

    public function refineUrl(Composition $composition): string
    {
        return $this->createTempDisabilityUrl . '?' . http_build_query(['refineFrom' => $composition->uuid]);
    }

    public function continueUrl(Composition $composition): string
    {
        return $this->createTempDisabilityUrl . '?' . http_build_query(['continueFrom' => $composition->uuid]);
    }

    /**
     * Cancellation reasons allowed for the conclusion being cancelled.
     *
     * The two conclusion types have separate reason dictionaries, so the list depends on
     * which one is open (TV 3.8.1.10.3, 3.8.2.15.3).
     *
     * @return array<string, string>
     */
    #[Computed]
    public function cancellationReasons(): array
    {
        $composition = $this->cancellingCompositionUuid
            ? $this->findLocalComposition($this->cancellingCompositionUuid)
            : null;

        if (!$composition) {
            return [];
        }

        return dictionary()->basics()
            ->byName($composition->type->cancellationReasonDictionary())
            ->asCodeDescription()
            ->all();
    }

    /**
     * Consequences the user must read before cancelling (TV 3.8.1.10.3, 3.8.2.15.3).
     */
    #[Computed]
    public function cancellationWarning(): string
    {
        $composition = $this->cancellingCompositionUuid
            ? $this->findLocalComposition($this->cancellingCompositionUuid)
            : null;

        return $composition?->isNewborn
            ? __('compositions.cancel.warning_message_newborn')
            : __('compositions.cancel.warning_message');
    }

    /**
     * Sign and submit the cancelComposition request.
     * ТЗ 3.8.1.10.4 / 3.8.2.15.4
     */
    public function cancelComposition(): void
    {
        try {
            // Validate on the form object so rules resolve against form.* properties
            // (component-level validate() looks for $this->reason, which does not exist).
            $this->form->validate(array_merge(
                $this->form->cancellationRules($this->cancellationReasons),
                $this->form->signingRules()
            ));
        } catch (ValidationException $e) {
            $this->setErrorBag($e->validator->getMessageBag());

            return;
        }

        $composition = $this->findLocalComposition((string) $this->cancellingCompositionUuid);

        if (!$composition) {
            return;
        }

        $this->authorize('cancel', $composition);

        // TV 3.8.1.10.1 — re-checked here rather than only when the modal opened. The
        // modal can stay open for as long as signing takes, and DRACS / DIIA processing
        // may well have started in between.
        if ($composition->isNewborn && ($this->fetchIntegration($composition) !== [])) {
            $this->closeCancellationModal();
            Session::flash('error', __('compositions.errors.cancel_has_integration'));

            return;
        }

        try {
            /** @var SignatureService $signer */
            $signer = app(SignatureService::class);

            $signedContent = $signer->signData(
                $this->form->toCancellationPayload($composition->uuid, $composition->type),
                $this->form->password,
                $this->form->knedp,
                $this->form->keyContainerUpload,
                Auth::user()->party->taxId
            );

            $job = EHealth::composition()->cancel($composition->uuid, ['data' => $signedContent])->validate();

            // eHealth processes the cancellation asynchronously, so the conclusion is not
            // in error yet. The job is recorded so the poller can finish the job off;
            // discarding it would leave the row permanently claiming to be pending.
            app(CompositionOperationRepository::class)->store($job, CompositionAsyncOperation::CANCEL, $this->patient(), [
                'composition_id' => $composition->id,
                'composition_type' => $composition->type,
                'encounter_uuid' => $composition->encounterUuid,
                'episode_uuid' => $composition->episodeOfCareUuid,
                'author_uuid' => $composition->authorUuid,
            ]);

            $this->closeCancellationModal();
            Session::flash('success', __('compositions.messages.cancellation_submitted'));
        } catch (Throwable $e) {
            Session::flash('error', $e->getMessage());

            Log::error('Failed to cancel composition', [
                'compositionUuid' => $this->cancellingCompositionUuid,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Advance every conclusion whose async request eHealth has now finished.
     *
     * Driven from the list by `wire:poll`, because cancellation and the ERLN retry both
     * complete after the user has left the modal behind (TV 3.8.2.14, 3.8.2.15.4).
     */
    public function pollAsyncJobs(): void
    {
        foreach ($this->pendingOperations() as $operation) {
            $this->advanceAsyncJob($operation);
        }

        unset($this->paginatedCompositions, $this->hasPendingAsyncJobs);
    }

    /**
     * Whether the list should keep polling.
     */
    #[Computed]
    public function hasPendingAsyncJobs(): bool
    {
        return $this->pendingOperations()->isNotEmpty();
    }

    /**
     * @return Collection<int, CompositionOperation>
     */
    private function pendingOperations(): Collection
    {
        return CompositionOperation::forPatient($this->patient())->pending()->with(['job', 'composition'])->get();
    }

    private function advanceAsyncJob(CompositionOperation $operation): void
    {
        try {
            $status = EHealth::composition()->getAsyncJobStatus($operation->remoteJobId)->validate();
        } catch (Throwable $exception) {
            report($exception);

            return;
        }
        $repository = app(CompositionOperationRepository::class);
        if ($status['status'] === CompositionJobStatus::FAILED->value) {
            $status['errors'] = $status['errors'] ?: [__('compositions.errors.async_job_failed')];
            $repository->fail($operation, $status);

            return;
        }
        if ($status['status'] !== CompositionJobStatus::DONE->value) {
            return;
        }
        try {
            $composition = $operation->composition;
            if ($operation->operation === CompositionAsyncOperation::CREATE) {
                $uuid = $status['compositionUuid'] ?? $composition?->uuid;
                if (!$uuid) {
                    $results = EHealth::composition()->search([
                        'subject' => $this->patient()->uuid,
                        'encounter' => $operation->encounterUuid,
                        'type' => $operation->compositionType->value,
                    ])->validate();
                    $uuid = collect($results)->sortByDesc('date')->pluck('identifier.value')->filter()->first();
                }
                if (!$uuid || !$operation->episodeUuid) {
                    return;
                }
                $details = EHealth::composition()->getById(
                    $this->patient()->uuid,
                    $uuid,
                    $operation->episodeUuid,
                    $operation->encounterUuid
                )->validate();
                $composition = Repository::composition()->store($details, $this->patient(), $operation->episodeUuid);
                if ($composition === null) {
                    return;
                }
            } elseif ($composition === null) {
                return;
            } elseif ($operation->operation === CompositionAsyncOperation::CANCEL) {
                $composition->update(['status' => CompositionStatus::ENTERED_IN_ERROR]);
            } elseif ($operation->operation === CompositionAsyncOperation::ERLN_RETRY) {
                $this->syncIntegration($composition);
            } elseif ($operation->operation === CompositionAsyncOperation::SIGN) {
                $details = $this->fetchComposition($composition);
                if ($details === []) {
                    return;
                }
                Repository::composition()->store($details, $this->patient(), $composition->episodeOfCareUuid);
            }
            // A remote DONE remains locally pending until its result has been stored.
            $repository->complete($operation, $composition);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    // ERLN re-send (МВТН only — ТЗ 3.8.2.14)

    /**
     * Open the ERLN re-send confirmation modal.
     * Only allowed when status = FINAL and erlnStatus = ERROR.
     */
    public function openErlnResendModal(string $compositionUuid): void
    {
        $composition = $this->findLocalComposition($compositionUuid);

        if (!$composition) {
            Session::flash('error', __('compositions.errors.not_found'));

            return;
        }

        abort_unless(Auth::user()->can('resendErln', $composition), 404);

        $this->resendingErlnCompositionUuid = $compositionUuid;
        $this->showErlnResendModal = true;
    }

    public function closeErlnResendModal(): void
    {
        $this->showErlnResendModal = false;
        $this->resendingErlnCompositionUuid = null;
    }

    /**
     * Execute the ERLN re-send request.
     * ТЗ 3.8.2.14 — patch_patients_composition__compositionId__erln
     */
    public function resendErln(): void
    {
        $composition = $this->findLocalComposition((string) $this->resendingErlnCompositionUuid);

        if (!$composition) {
            return;
        }

        $this->authorize('resendErln', $composition);

        try {
            $job = EHealth::composition()->resendErln($composition->uuid)->validate();

            // The retry is asynchronous: the ERLN status will not change until the job
            // finishes, so the job is recorded and the list poller reports the outcome
            // instead of an immediate refresh that can only show the stale status.
            app(CompositionOperationRepository::class)->store($job, CompositionAsyncOperation::ERLN_RETRY, $this->patient(), [
                'composition_id' => $composition->id,
                'composition_type' => $composition->type,
                'encounter_uuid' => $composition->encounterUuid,
                'episode_uuid' => $composition->episodeOfCareUuid,
                'author_uuid' => $composition->authorUuid,
            ]);

            $this->closeErlnResendModal();
            Session::flash('success', __('compositions.messages.erln_resent_successfully'));
        } catch (Throwable $e) {
            Session::flash('error', $e->getMessage());

            Log::error('Failed to resend МВТН to ERLN', [
                'compositionUuid' => $this->resendingErlnCompositionUuid,
                'error' => $e->getMessage(),
            ]);
        }
    }

    // Private helpers

    /**
     * Re-read ERLN / DRACS status without opening the details (TV 3.8.1.8.5, 3.8.2.10.5).
     */
    public function refreshIntegration(string $compositionUuid): void
    {
        $composition = $this->findLocalComposition($compositionUuid);

        if (!$composition?->hasReadContext) {
            Session::flash('error', __('compositions.errors.missing_read_context'));

            return;
        }

        abort_unless(Auth::user()->can('view', $composition), 404);

        try {
            $this->syncIntegration($composition);
            Session::flash('success', __('compositions.messages.integration_refreshed'));
        } catch (EHealthConnectionException | EHealthException $exception) {
            $exception->handle('Error refreshing composition integration data');
        }
    }

    /**
     * Paginate compositions from the local database.
     */
    private function paginateLocalCompositions(): LengthAwarePaginator
    {
        $query = Composition::forPatient($this->patient())
            ->with([
                'typeConcept.coding',
                'categoryConcept.coding',
                'encounter',
                'episodeOfCare',
                'eventPeriod',
                'integrations',
            ])
            ->recentlyUpdatedFirst();

        if ($this->filterType) {
            $query->whereRelation('typeConcept.coding', 'code', $this->filterType);
        }

        if ($this->filterStatus) {
            $query->where('status', $this->filterStatus);
        }

        if ($this->filterEncounterId) {
            $query->forEncounter($this->filterEncounterId);
        }

        if ($this->filterEpisodeOfCareId) {
            $query->whereHas(
                'episodeOfCare',
                fn ($episode) => $episode->where('value', $this->filterEpisodeOfCareId)
            );
        }

        if ($this->filterSectionFocusUuid) {
            $query->forFocus($this->filterSectionFocusUuid);
        }

        return $query->paginate(config('ehealth.api.page_size', 15));
    }

    /**
     * Pull matching compositions from eHealth into the local table.
     * ТЗ 3.8.1.9 / 3.8.2.11
     */
    private function refreshFromEHealth(): void
    {
        // TV 3.8.1.9 / 3.8.2.11 — searching is its own capability, and a user who may not
        // see conclusions here must not be able to pull them into the local table either.
        abort_unless(Auth::user()->can('viewAny', Composition::class), 404);

        try {
            // `subject` and `focus` are mutually exclusive, so searching by an explicit
            // focus replaces the implicit search by the patient being viewed.
            $searchByFocus = filled($this->filterSectionFocusUuid);
            $limit = (int) config('ehealth.api.page_size', 15);

            $query = array_filter([
                'subject' => $searchByFocus ? null : $this->uuid,
                'focus' => $searchByFocus ? $this->filterSectionFocusUuid : null,
                'type' => $this->filterType ?: null,
                'status' => $this->filterStatus ?: null,
                'encounter' => $this->filterEncounterId ?: null,
                'episodeOfCare' => $this->filterEpisodeOfCareId ?: null,
                // Synchronize remote pages before applying local pagination.
                'offset' => 0,
                'limit' => $limit,
            ]);

            $seen = [];
            do {
                $items = EHealth::composition()->search($query)->validate();
                $newItems = array_filter(
                    $items,
                    static fn (array $item): bool =>
                    !isset($seen[data_get($item, 'identifier.value')])
                );
                if ($newItems === []) {
                    break;
                }
                $this->syncLocalCompositions($newItems);
                foreach ($newItems as $item) {
                    $seen[data_get($item, 'identifier.value')] = true;
                }
                $query['offset'] = ($query['offset'] ?? 0) + $limit;
            } while (count($items) === $limit);
        } catch (EHealthConnectionException | EHealthException $exception) {
            $exception->handle('Error searching compositions');
        }
    }

    /**
     * Conclusions of a merged-in unidentified record that may now be clarified.
     *
     * @return Collection<int, Composition>
     */
    #[Computed]
    public function clarifiableUnidentifiedConclusions(): Collection
    {
        $patient = $this->patient();

        if ($patient instanceof Preperson) {
            return collect();
        }

        $mergedPrepersonIds = MergeRequest::query()
            ->whereMasterPersonId($patient->id)
            ->whereIn('status', [MergeRequestStatus::APPROVED->value, MergeRequestStatus::SIGNED->value])
            ->pluck('merge_person_id');

        if ($mergedPrepersonIds->isEmpty()) {
            return collect();
        }

        return Composition::query()
            ->whereIn('preperson_id', $mergedPrepersonIds)
            ->ofType(CompositionType::TEMP_DISABILITY)
            ->whereHas(
                'categoryConcept.coding',
                static fn ($query) => $query->where('code', CompositionCategory::SICKNESS->value)
            )
            ->signed()
            // Only the newest one per merged record is offered: clarifying an already
            // superseded conclusion is not what TV 3.8.2.12 describes.
            ->orderByDesc('date')
            ->get()
            ->unique('preperson_id')
            ->values();
    }

    /**
     * Upsert compositions returned by the eHealth search into the local DB.
     *
     * Search rows are merged into the local record, then enriched when read context is available.
     */
    private function syncLocalCompositions(array $compositions): void
    {
        $patient = $this->patient();
        $repository = Repository::composition();

        foreach ($compositions as $item) {
            if (!is_array($item) || !data_get($item, 'identifier.value')) {
                continue;
            }

            // A focus search can return another patient's conclusion; never reassign it.
            $subjectUuid = data_get($item, 'subject.value');
            if ($subjectUuid && $subjectUuid !== $this->uuid) {
                continue;
            }
            if (!$subjectUuid && filled($this->filterSectionFocusUuid)) {
                continue;
            }
            if (!$subjectUuid) {
                $item['subject'] = [
                    'value' => $this->uuid,
                    'type' => [
                        'coding' => [[
                            'system' => 'eHealth/resources',
                            'code' => $patient instanceof Preperson ? 'preperson' : 'person',
                        ]],
                    ],
                ];
            }

            $composition = $repository->store(
                $item,
                $patient,
                data_get($item, 'episodeOfCare.value')
            );

            // Synchronization loads the full record; opening its detail modal stays local.
            if ($composition?->hasReadContext && Auth::user()->can('view', $composition)) {
                $repository->store($this->fetchComposition($composition), $patient, $composition->episodeOfCareUuid);
            }
        }
    }

    /**
     * Find a locally stored Composition by UUID.
     */
    private function findLocalComposition(string $uuid): ?Composition
    {
        return Composition::forPatient($this->patient())->whereUuid($uuid)->first();
    }

    // Render

    public function render(): View
    {
        return view('livewire.composition.composition-index');
    }
}
