<?php

declare(strict_types=1);

namespace App\Livewire\Person\Records;

use App\Classes\eHealth\EHealth;
use App\Enums\Composition\CompositionAsyncOperation;
use App\Enums\Composition\CompositionConfiguration;
use App\Enums\Composition\CompositionJobStatus;
use App\Enums\Composition\CompositionStatus;
use App\Enums\Composition\CompositionType;
use App\Exceptions\EHealth\EHealthConnectionException;
use App\Exceptions\EHealth\EHealthException;
use App\Exceptions\MedicalEvents\CompositionGuardException;
use App\Livewire\Composition\CompositionComponent;
use App\Livewire\Composition\Forms\CompositionCancellationForm;
use App\Models\MedicalEvents\Sql\Composition;
use App\Models\MedicalEvents\Sql\CompositionOperation;
use App\Models\Preperson;
use App\Repositories\MedicalEvents\Repository;
use App\Services\SignatureService;
use Carbon\CarbonImmutable;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\WithFileUploads;
use Livewire\WithPagination;
use RuntimeException;
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
class PatientCompositions extends CompositionComponent
{
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

    #[Locked]
    public ?string $cancellingCompositionDate = null;

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

    /** Validated search rows retain their real subject and do not transfer ownership to this card. */
    #[Locked]
    public ?array $focusSearchResults = null;

    /** @var string|null UUID of the composition being re-sent to ERLN */
    #[Locked]
    public ?string $resendingErlnCompositionUuid = null;

    /** @var bool Whether to show the ERLN re-send confirmation modal */
    public bool $showErlnResendModal = false;

    /** Only primitive snapshots cross Livewire requests; operations stay in the repository. */
    #[Locked]
    public array $trackedOperationStates = [];

    // Lifecycle

    protected function initializeComponent(): void
    {
        foreach ($this->pendingOperations() as $operation) {
            $this->trackedOperationStates[$operation->id] = ['status' => $operation->status, 'error' => $operation->error];
        }
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

    public function sync(): void
    {
        $this->focusSearchResults = null;
        $this->resetPage();

        if ($this->refreshFromEHealth(applyFilters: false)) {
            Session::flash('success', __('compositions.messages.sync_success'));
        }

        unset($this->paginatedCompositions);
    }

    public function resetFilters(): void
    {
        $this->focusSearchResults = null;
        $this->reset([
            'filterType',
            'filterStatus',
            'filterEncounterId',
            'filterEpisodeOfCareId',
            'filterSectionFocusUuid',
        ]);

        $this->resetPage();
    }

    // Detail view

    public function viewFocusResult(string $uuid): void
    {
        if (Auth::user()->cannot('viewAny', Composition::class)) {
            Session::flash('error', __('compositions.errors.search_not_allowed'));

            return;
        }
        $result = collect($this->focusSearchResults ?? [])->first(
            static fn (array $row): bool => data_get($row, 'identifier.value') === $uuid
        );
        if ($result === null) {
            Session::flash('error', __('compositions.errors.not_found'));

            return;
        }
        $this->compositionDetail = $result;
        $this->viewingCompositionUuid = $uuid;
        $this->integrationData = null;
        $this->showDetailModal = true;
    }

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

        if (Auth::user()->cannot('view', $composition)) {
            Session::flash('error', __('compositions.errors.view_not_allowed'));

            return;
        }

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

        if (Auth::user()->cannot('view', $composition)) {
            Session::flash('error', __('compositions.errors.view_not_allowed'));

            return;
        }

        try {
            $templateId = $composition->type->printTemplateId();
            $response = EHealth::composition()->getPrintForm(
                $composition->patientUuid,
                $composition->uuid,
                $composition->episodeOfCareUuid,
                $composition->encounterUuid,
                $templateId
            );

            $document = $response->validate();
            $this->showDetailModal = false;
            $this->printFormHtml = $document['content'];
            $this->showPrintModal = true;
        } catch (ValidationException $exception) {
            Session::flash('error', __('compositions.errors.invalid_response'));
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

        if (Auth::user()->cannot('cancel', $composition)) {
            Session::flash('error', __('compositions.errors.cancel_not_allowed'));

            return;
        }

        if (Repository::compositionOperation()->hasPendingForComposition($composition)) {
            Session::flash('info', __('compositions.async.pending'));

            return;
        }
        try {
            $snapshot = $this->currentCompositionSnapshot($composition);
            if (Auth::user()->cannot('cancel', $snapshot)) {
                Session::flash('error', __('compositions.errors.cancel_not_allowed'));

                return;
            }
            if ($snapshot->isNewborn && $this->fetchIntegration($snapshot) !== []) {
                throw new CompositionGuardException(__('compositions.errors.cancel_has_integration'));
            }
            $this->cancellingCompositionUuid = $compositionUuid;
            $this->cancellingCompositionDate = $snapshot->date;
            unset($this->cancellationReasons, $this->cancellationWarning);
            if ($this->cancellationReasons === []) {
                // A replacement reason may have a longer deadline, so retain every still-allowed reason.
                if ($snapshot->isTempDisability) {
                    $this->assertCancellationAllowed($snapshot->date);
                }
                throw new CompositionGuardException(__('compositions.errors.invalid_response'));
            }
            $this->form->resetCancellationFields();
            $this->form->resetSigningFields();
            $this->showSignatureModal = true;
        } catch (CompositionGuardException $exception) {
            Session::flash('error', $exception->getMessage());
        } catch (ValidationException $exception) {
            Session::flash('error', __('compositions.errors.invalid_response'));
        } catch (EHealthConnectionException | EHealthException $exception) {
            $exception->handle('Could not check cancellation of the medical conclusion');
        }
    }

    public function closeCancellationModal(): void
    {
        $this->showSignatureModal = false;
        $this->cancellingCompositionUuid = null;
        $this->cancellingCompositionDate = null;
        $this->form->resetCancellationFields();
        $this->form->resetSigningFields();
    }

    private function assertCancellationWithinConfiguredPeriod(Composition $composition): bool
    {
        if (!$composition->isTempDisability) {
            return true;
        }
        try {
            $this->assertCancellationAllowed($composition->date, $this->form->reason);
        } catch (CompositionGuardException $exception) {
            Session::flash('error', $exception->getMessage());
            $this->addError('form.guard', $exception->getMessage());

            return false;
        }

        return true;
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

        $reasons = dictionary()->basics()
            ->byName($composition->type->cancellationReasonDictionary())
            ->asCodeDescription()
            ->all();

        if (!$composition->isTempDisability || $this->cancellingCompositionDate === null) {
            return $reasons;
        }
        return array_filter($reasons, function (string $reason): bool {
            try {
                $this->assertCancellationAllowed($this->cancellingCompositionDate, $reason);

                return true;
            } catch (CompositionGuardException) {
                return false;
            }
        }, ARRAY_FILTER_USE_KEY);
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
        $composition = $this->findLocalComposition((string) $this->cancellingCompositionUuid);

        if (!$composition) {
            return;
        }

        if (Auth::user()->cannot('cancel', $composition)) {
            Session::flash('error', __('compositions.errors.cancel_not_allowed'));

            return;
        }

        $lock = Cache::lock('composition-write:'.$composition->uuid, 600);
        if (!$lock->get()) {
            Session::flash('info', __('compositions.async.pending'));

            return;
        }
        try {
            if (Repository::compositionOperation()->hasPendingForComposition($composition)) {
                Session::flash('info', __('compositions.async.pending'));

                return;
            }
            $snapshot = $this->currentCompositionSnapshot($composition);
            if (Auth::user()->cannot('cancel', $snapshot)) {
                Session::flash('error', __('compositions.errors.cancel_not_allowed'));

                return;
            }
            $this->cancellingCompositionDate = $snapshot->date;
            unset($this->cancellationReasons);
            if (!$this->assertCancellationWithinConfiguredPeriod($snapshot)) {
                return;
            }
            try {
                $this->form->validate(array_merge(
                    $this->form->cancellationRules($this->cancellationReasons),
                    $this->form->signingRules()
                ));
            } catch (ValidationException $exception) {
                $this->setErrorBag($exception->validator->getMessageBag());

                return;
            }
            // Re-read integration immediately before KEP; opening the modal is not sufficient evidence.
            if ($snapshot->isNewborn && $this->fetchIntegration($snapshot) !== []) {
                throw new CompositionGuardException(__('compositions.errors.cancel_has_integration'));
            }
            /** @var SignatureService $signer */
            $signer = app(SignatureService::class);

            try {
                $signedContent = $signer->signData(
                    $this->buildCancellationPayload($composition->uuid, $composition->type),
                    $this->form->password,
                    $this->form->knedp,
                    $this->form->keyContainerUpload,
                    Auth::user()->party->taxId
                );
            } catch (RuntimeException $exception) {
                $this->addError('form.guard', $exception->getMessage());
                Session::flash('error', $exception->getMessage());

                return;
            }

            $job = EHealth::composition()->cancel($composition->uuid, ['data' => $signedContent])->validate();

            // eHealth processes the cancellation asynchronously, so the conclusion is not
            // in error yet. The job is recorded so the poller can finish the job off;
            // discarding it would leave the row permanently claiming to be pending.
            $operation = Repository::compositionOperation()->store($job, CompositionAsyncOperation::CANCEL, $this->patient(), [
                'composition_id' => $composition->id,
                'composition_type' => $composition->type,
                'encounter_uuid' => $composition->encounterUuid,
                'episode_uuid' => $composition->episodeOfCareUuid,
                'author_uuid' => $composition->authorUuid,
            ]);
            $this->trackedOperationStates[$operation->id] = ['status' => CompositionJobStatus::PENDING->value, 'error' => null];

            $this->closeCancellationModal();
            if (!$this->queueCompositionOperation($operation)) {
                Session::flash('error', __('compositions.errors.job_status_unavailable'));

                return;
            }
            Session::flash('info', __('compositions.messages.cancellation_submitted'));
        } catch (CompositionGuardException $exception) {
            $this->addError('form.guard', $exception->getMessage());
            Session::flash('error', $exception->getMessage());
        } catch (ValidationException $exception) {
            Session::flash('error', __('compositions.errors.invalid_response'));
        } catch (EHealthConnectionException | EHealthException $exception) {
            $exception->handle('Failed to cancel the medical conclusion');
        } catch (Throwable $exception) {
            Session::flash('error', __($this->showSignatureModal
                ? 'clinical-storage.write_failed' : 'compositions.errors.async_processing_failed'));

            Log::error('Failed to cancel composition', [
                'exceptionType' => $exception::class,
            ]);
        } finally {
            $lock->release();
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
        if (Auth::user()->cannot('viewAny', Composition::class)) {
            return;
        }
        $feedback = null;
        foreach (Repository::compositionOperation()->trackedForPatient($this->patient(), array_keys($this->trackedOperationStates)) as $operation) {
            if (Auth::user()->cannot('readOperation', [Composition::class, $operation])) {
                continue;
            }
            $previous = $this->trackedOperationStates[$operation->id];
            if ($operation->status === CompositionJobStatus::PENDING->value) {
                if ($operation->error !== null && $operation->error !== $previous['error']) {
                    $feedback = ['error', $operation->error];
                }
                $this->trackedOperationStates[$operation->id] = ['status' => $operation->status, 'error' => $operation->error];

                continue;
            }
            unset($this->trackedOperationStates[$operation->id]);
            if ($operation->status === CompositionJobStatus::FAILED->value) {
                $feedback = ['error', $operation->error ?: __('compositions.errors.async_job_failed')];
            } elseif ($feedback === null) {
                // Completion means the worker validated and stored the expected resource state.
                $key = match ($operation->operation) {
                    CompositionAsyncOperation::CANCEL => 'cancellation_confirmed',
                    CompositionAsyncOperation::ERLN_RETRY => 'erln_retry_processed',
                    default => 'operation_confirmed',
                };
                $feedback = ['success', __('compositions.messages.'.$key)];
            }
        }
        if ($feedback !== null) {
            Session::flash($feedback[0], $feedback[1]);
        }
        unset($this->paginatedCompositions, $this->hasPendingAsyncJobs, $this->pendingOperations);
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
    #[Computed]
    public function pendingOperations(): Collection
    {
        if (Auth::user()->cannot('viewAny', Composition::class)) {
            return collect();
        }

        return Repository::compositionOperation()->pendingForPatient($this->patient())
            ->filter(fn (CompositionOperation $operation): bool => !Auth::user()->cannot('readOperation', [Composition::class, $operation]));
    }

    public function retryAsyncJob(string $jobId): void
    {
        if (Auth::user()->cannot('viewAny', Composition::class)) {
            Session::flash('error', __('compositions.errors.view_not_allowed'));

            return;
        }
        $operation = Repository::compositionOperation()->findForPatient($this->patient(), $jobId);
        if ($operation === null || $operation->status !== CompositionJobStatus::PENDING->value) {
            return;
        }
        if (Auth::user()->cannot('readOperation', [Composition::class, $operation])) {
            Session::flash('error', __('compositions.errors.view_not_allowed'));

            return;
        }
        // Retry only the GET/worker for the accepted job; never repeat its write or KEP.
        try {
            $this->trackedOperationStates[$operation->id] = ['status' => CompositionJobStatus::PENDING->value, 'error' => null];
            if (!$this->queueCompositionOperation($operation)) {
                Session::flash('error', __('compositions.errors.job_status_unavailable'));

                return;
            }
            Session::flash('info', __('compositions.messages.result_read_requested'));
        } catch (Throwable $exception) {
            Session::flash('error', __('compositions.errors.async_processing_failed'));
            Log::error('Could not queue the existing medical conclusion result', ['exceptionType' => $exception::class]);
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

        if (Auth::user()->cannot('resendErln', $composition)) {
            Session::flash('error', __('compositions.errors.erln_resend_not_allowed'));

            return;
        }
        if (Repository::compositionOperation()->hasPendingForComposition($composition)) {
            Session::flash('info', __('compositions.async.pending'));

            return;
        }
        try {
            $snapshot = $this->currentCompositionSnapshot($composition);
            $integration = $this->fetchIntegration($snapshot);
            if (Auth::user()->cannot('resendErln', [$snapshot, $integration])) {
                Session::flash('error', __('compositions.errors.erln_resend_not_error'));

                return;
            }
            // TV39 requires the exact failed ERLN task and its message before asking for a retry.
            $this->integrationData = $integration;
            $this->resendingErlnCompositionUuid = $compositionUuid;
            $this->showErlnResendModal = true;
        } catch (CompositionGuardException $exception) {
            Session::flash('error', $exception->getMessage());
        } catch (ValidationException $exception) {
            Session::flash('error', __('compositions.errors.invalid_response'));
        } catch (EHealthConnectionException | EHealthException $exception) {
            $exception->handle('Could not check the ERLN retry');
        }
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

        if (Auth::user()->cannot('resendErln', $composition)) {
            Session::flash('error', __('compositions.errors.erln_resend_not_allowed'));

            return;
        }
        $lock = Cache::lock('composition-write:'.$composition->uuid, 600);
        if (!$lock->get()) {
            Session::flash('info', __('compositions.async.pending'));

            return;
        }
        try {
            if (Repository::compositionOperation()->hasPendingForComposition($composition)) {
                Session::flash('info', __('compositions.async.pending'));

                return;
            }
            $snapshot = $this->currentCompositionSnapshot($composition);
            $integration = $this->fetchIntegration($snapshot);
            if (Auth::user()->cannot('resendErln', [$snapshot, $integration])) {
                Session::flash('error', __('compositions.errors.erln_resend_not_error'));

                return;
            }
            $job = EHealth::composition()->resendErln($composition->uuid)->validate();

            // The retry is asynchronous: the ERLN status will not change until the job
            // finishes, so the job is recorded and the list poller reports the outcome
            // instead of an immediate refresh that can only show the stale status.
            $operation = Repository::compositionOperation()->store($job, CompositionAsyncOperation::ERLN_RETRY, $this->patient(), [
                'composition_id' => $composition->id,
                'composition_type' => $composition->type,
                'encounter_uuid' => $composition->encounterUuid,
                'episode_uuid' => $composition->episodeOfCareUuid,
                'author_uuid' => $composition->authorUuid,
            ]);
            $this->trackedOperationStates[$operation->id] = ['status' => CompositionJobStatus::PENDING->value, 'error' => null];

            $this->closeErlnResendModal();
            if (!$this->queueCompositionOperation($operation)) {
                Session::flash('error', __('compositions.errors.job_status_unavailable'));

                return;
            }
            Session::flash('info', __('compositions.messages.erln_resent_successfully'));
        } catch (CompositionGuardException $exception) {
            Session::flash('error', $exception->getMessage());
        } catch (ValidationException $exception) {
            Session::flash('error', __('compositions.errors.invalid_response'));
        } catch (EHealthConnectionException | EHealthException $exception) {
            $exception->handle('Failed to resend the medical conclusion to ERLN');
        } catch (Throwable $exception) {
            Session::flash('error', __($this->showErlnResendModal
                ? 'clinical-storage.write_failed' : 'compositions.errors.async_processing_failed'));

            Log::error('Failed to resend МВТН to ERLN', [
                'exceptionType' => $exception::class,
            ]);
        } finally {
            $lock->release();
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

        if (Auth::user()->cannot('view', $composition)) {
            Session::flash('error', __('compositions.errors.view_not_allowed'));

            return;
        }

        try {
            $this->syncIntegration($composition);
            Session::flash('success', __('compositions.messages.integration_refreshed'));
        } catch (ValidationException $exception) {
            Session::flash('error', __('compositions.errors.invalid_response'));
        } catch (EHealthConnectionException | EHealthException $exception) {
            $exception->handle('Error refreshing composition integration data');
        }
    }

    /**
     * Paginate compositions from the local database.
     */
    private function paginateLocalCompositions(): LengthAwarePaginator
    {
        return Repository::composition()->paginateForPatient($this->patient(), [
            'type' => $this->filterType,
            'status' => $this->filterStatus,
            'encounterUuid' => $this->filterEncounterId,
            'episodeUuid' => $this->filterEpisodeOfCareId,
            'focusUuid' => $this->filterSectionFocusUuid,
        ], (int) config('ehealth.api.page_size', 15));
    }

    /**
     * Pull matching compositions from eHealth into the local table.
     * ТЗ 3.8.1.9 / 3.8.2.11
     */
    private function refreshFromEHealth(bool $applyFilters = true): bool
    {
        // TV 3.8.1.9 / 3.8.2.11 — searching is its own capability, and a user who may not
        // see conclusions here must not be able to pull them into the local table either.
        if (Auth::user()->cannot('viewAny', Composition::class)) {
            Session::flash('error', __('compositions.errors.search_not_allowed'));

            return false;
        }

        try {
            // `subject` and `focus` are mutually exclusive, so searching by an explicit
            // focus replaces the implicit search by the patient being viewed.
            $searchByFocus = $applyFilters && filled($this->filterSectionFocusUuid);
            $limit = (int) config('ehealth.api.page_size', 15);

            $query = array_filter([
                'subject' => $searchByFocus ? null : $this->uuid,
                'focus' => $searchByFocus ? $this->filterSectionFocusUuid : null,
                'type' => $applyFilters ? ($this->filterType ?: null) : null,
                'status' => $applyFilters ? ($this->filterStatus ?: null) : null,
                'encounter' => $applyFilters ? ($this->filterEncounterId ?: null) : null,
                'episodeOfCare' => $applyFilters ? ($this->filterEpisodeOfCareId ?: null) : null,
                // Synchronize remote pages before applying local pagination.
                'offset' => 0,
                'limit' => $limit,
            ]);

            $results = EHealth::composition()->searchAll($query);

            // Publish search results only after every page validates, so a malformed later page cannot claim completion.
            $this->syncLocalCompositions($results, $searchByFocus);
            $this->focusSearchResults = $searchByFocus ? array_values($results) : null;

            return true;
        } catch (ValidationException $exception) {
            Session::flash('error', __('compositions.errors.invalid_response'));

            return false;
        } catch (EHealthConnectionException | EHealthException $exception) {
            $exception->handle('Error searching compositions');

            return false;
        } catch (Throwable $exception) {
            Session::flash('error', __('clinical-storage.write_failed'));
            Log::error('Could not store the medical conclusion search', ['exceptionType' => $exception::class]);

            return false;
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
        return Repository::composition()->clarifiableUnidentifiedForPatient($this->patient());
    }

    /**
     * Upsert compositions returned by the eHealth search into the local DB.
     *
     * Validate all readable details before atomically publishing the search snapshot.
     */
    private function syncLocalCompositions(array $compositions, bool $searchByFocus): void
    {
        $patient = $this->patient();
        $repository = Repository::composition();
        $validated = [];

        foreach ($compositions as $item) {
            if (!is_array($item) || !data_get($item, 'identifier.value')) {
                continue;
            }

            // A focus search can return another patient's conclusion; never reassign it.
            $subjectUuid = data_get($item, 'subject.value');
            if ($subjectUuid && $subjectUuid !== $this->uuid) {
                continue;
            }
            if (!$subjectUuid && $searchByFocus) {
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

            // Authorize the prospective resource without storing a partial search row.
            $composition = $repository->findForPatient($patient, data_get($item, 'identifier.value'))
                ?? new Composition([
                    'uuid' => data_get($item, 'identifier.value'),
                    $patient instanceof Preperson ? 'preperson_id' : 'person_id' => $patient->id,
                ]);
            $episodeUuid = data_get($item, 'episodeOfCare.value') ?? $composition->episodeOfCareUuid;
            $encounterUuid = data_get($item, 'encounter.value') ?? $composition->encounterUuid;

            // Search remains independently permitted; only readable resources receive full enrichment.
            if (filled($episodeUuid) && filled($encounterUuid)) {
                if (Auth::user()->cannot('view', $composition)) {
                    $validated[] = [$item, $episodeUuid];

                    continue;
                }
                $item = $this->fetchCompositionDetails(
                    $this->uuid,
                    $composition->uuid,
                    $episodeUuid,
                    $encounterUuid,
                    array_filter([
                        'author.value' => data_get($item, 'author.value') ?? $composition->authorUuid,
                        'type.coding.0.code' => data_get($item, 'type.coding.0.code') ?? $composition->type?->value,
                        'subject.type.coding.0.code' => $patient instanceof Preperson ? 'preperson' : 'person',
                    ], static fn ($value): bool => $value !== null)
                );
            }
            $validated[] = [$item, $episodeUuid];
        }

        // A malformed later detail or storage failure must leave every previous local record unchanged.
        $repository->storeMany($validated, $patient);
    }

    /**
     * Find a locally stored Composition by UUID.
     */
    private function findLocalComposition(string $uuid): ?Composition
    {
        return Repository::composition()->findForPatient($this->patient(), $uuid);
    }

    // Render

    public function render(): View
    {
        return view('livewire.composition.composition-index');
    }

    /** Build the cancellation document with the type's validated reason choice and the author's text. */
    protected function buildCancellationPayload(string $compositionUuid, CompositionType $type): array
    {
        return [
            'id' => $compositionUuid,
            'reason' => [
                'coding' => [['system' => 'eHealth/'.$type->cancellationReasonDictionary(), 'code' => $this->form->reason]],
                'text' => $this->form->reasonText,
            ],
        ];
    }

    /** A day-based timeout includes the configured final calendar day. */
    protected function assertCancellationAllowed(?string $signedDate, ?string $reason = null): void
    {
        if (!CompositionConfiguration::CANCEL_ENABLED->enabled()) {
            return;
        }
        $days = CompositionConfiguration::CANCEL_DAYS->days();
        if ($reason !== null && CompositionConfiguration::REPLACE_ENABLED->enabled()
            && in_array($reason, CompositionConfiguration::REPLACE_REASONS->codes(allowEmpty: true), true)) {
            $days = CompositionConfiguration::REPLACE_CANCEL_DAYS->days();
        }
        if (blank($signedDate)) {
            throw new CompositionGuardException(__('compositions.errors.configuration_unavailable'));
        }
        try {
            $signed = CarbonImmutable::parse($signedDate)->setTimezone(config('app.timezone'))->startOfDay();
        } catch (Throwable) {
            throw new CompositionGuardException(__('compositions.errors.configuration_unavailable'));
        }
        if ($signed->isFuture() || $signed->addDays($days)->lt(CarbonImmutable::today())) {
            throw new CompositionGuardException(__('compositions.errors.cancel_timeout'));
        }
    }
}
