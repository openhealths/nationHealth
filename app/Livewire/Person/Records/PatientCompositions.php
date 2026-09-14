<?php

declare(strict_types=1);

namespace App\Livewire\Person\Records;

use App\Classes\eHealth\EHealth;
use App\Enums\MergeRequest\Status as MergeRequestStatus;
use App\Enums\Person\CompositionAsyncOperation;
use App\Enums\Person\CompositionCategory;
use App\Enums\Person\CompositionStatus;
use App\Enums\Person\CompositionType;
use App\Exceptions\EHealth\EHealthConnectionException;
use App\Exceptions\EHealth\EHealthException;
use App\Livewire\Composition\Forms\CompositionCancellationForm;
use App\Models\MedicalEvents\Sql\Composition;
use App\Models\MergeRequest;
use App\Models\Preperson;
use App\Services\MedicalEvents\CompositionLifecycleService;
use App\Services\SignatureService;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
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

    // ── Filters ──────────────────────────────────────────────────────────────

    public string $filterType = '';

    public string $filterStatus = '';

    public string $filterEncounterId = '';

    public string $filterEpisodeOfCareId = '';

    public string $filterSectionFocusUuid = '';

    // ── ERLN re-send state ────────────────────────────────────────────────────

    /** @var string|null UUID of the composition being re-sent to ERLN */
    public ?string $resendingErlnCompositionUuid = null;

    /** @var bool Whether to show the ERLN re-send confirmation modal */
    public bool $showErlnResendModal = false;

    // ──────────────────────────────────────────────────────────────────────────
    // Lifecycle
    // ──────────────────────────────────────────────────────────────────────────

    protected function initializeComponent(): void
    {
        // Nothing extra to initialise here; patient data is loaded by BasePatientComponent.
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Computed properties
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Paginated compositions, always read from the local table.
     *
     * Searching refreshes that table from eHealth first rather than returning the raw
     * API payload, so the view never has to deal with two different record shapes.
     */
    #[Computed]
    public function paginatedCompositions(): LengthAwarePaginator
    {
        if ($this->isSearching) {
            $this->refreshFromEHealth();
        }

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

    // ──────────────────────────────────────────────────────────────────────────
    // Search & filtering
    // ──────────────────────────────────────────────────────────────────────────

    public function search(): void
    {
        $this->isSearching = true;
        $this->resetPage();
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

    // ──────────────────────────────────────────────────────────────────────────
    // Detail view
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Load composition detail from eHealth API (getComposition).
     * ТЗ 3.8.1.6.1 / 3.8.2.8.1
     */
    public function viewComposition(string $compositionUuid): void
    {
        $this->viewingCompositionUuid = $compositionUuid;
        $this->compositionDetail = null;
        $this->integrationData = null;
        $this->printFormHtml = null;

        $composition = $this->findLocalComposition($compositionUuid);

        if (!$composition?->hasReadContext) {
            Session::flash('error', __('patients.composition.errors.missing_read_context'));

            return;
        }

        $this->authorize('view', $composition);

        try {
            $response = EHealth::composition()->getById(
                $composition->patientUuid,
                $composition->uuid,
                $composition->episodeOfCareUuid,
                $composition->encounterUuid
            );

            $this->compositionDetail = $response->getData() ?: ($response->json() ?? []);
            $this->showDetailModal = true;

            try {
                $this->integrationData = $this->lifecycle()->syncIntegration($composition);
            } catch (EHealthConnectionException | EHealthException) {
                $this->integrationData = data_get($composition->data, '_integration', []);
            }
        } catch (EHealthConnectionException | EHealthException $exception) {
            $exception->handle('Error fetching composition detail');
        }
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
            Session::flash('error', __('patients.composition.errors.missing_read_context'));

            return;
        }

        $this->authorize('view', $composition);

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
            Session::flash('error', __('patients.composition.errors.print_form_failed'));

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

    // ──────────────────────────────────────────────────────────────────────────
    // Cancellation (entered in error)
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Open the cancellation modal after pre-flight checks.
     * ТЗ 3.8.1.10.1 / 3.8.2.15.1
     */
    public function openCancellationModal(string $compositionUuid): void
    {
        $composition = $this->findLocalComposition($compositionUuid);

        if (!$composition) {
            Session::flash('error', __('patients.composition.errors.not_found'));

            return;
        }

        // Status and authorship are checked by the policy. The remaining preconditions from
        // TV 3.8.1.10.1 and 3.8.2.15.1 — that no integration process has started, and that
        // the cancellation timeout has not elapsed — are only known to eHealth, which
        // rejects the request itself.
        $response = Gate::inspect('cancel', $composition);

        if (!$response->allowed()) {
            Session::flash('error', $response->message());

            return;
        }

        // TV 3.8.1.10.1 — a birth conclusion cannot be cancelled once DRACS / DIIA
        // processing has started. The call is skipped for МВТН: those may already
        // have an ERLN record and are cancelled together with it.
        if ($composition->isNewborn && $this->lifecycle()->hasIntegrationProcesses($composition)) {
            Session::flash('error', __('patients.composition.errors.cancel_has_integration'));

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
            ? __('patients.composition.cancel.warning_message_newborn')
            : __('patients.composition.cancel.warning_message');
    }

    /**
     * Sign and submit the cancelComposition request.
     * ТЗ 3.8.1.10.4 / 3.8.2.15.4
     */
    public function cancelComposition(): void
    {
        try {
            $this->validate(array_merge(
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
        if ($composition->isNewborn && $this->lifecycle()->hasIntegrationProcesses($composition)) {
            $this->closeCancellationModal();
            Session::flash('error', __('patients.composition.errors.cancel_has_integration'));

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

            $job = $this->lifecycle()->cancel($composition->uuid, $signedContent);

            // eHealth processes the cancellation asynchronously, so the conclusion is not
            // in error yet. The job is recorded so the poller can finish the job off;
            // discarding it would leave the row permanently claiming to be pending.
            $composition->update([
                'async_job_id' => $job['id'],
                'async_job_status' => $job['status'] ?? CompositionLifecycleService::JOB_PENDING,
                'async_job_operation' => CompositionAsyncOperation::CANCEL->value,
                'async_job_error' => null,
            ]);

            $this->closeCancellationModal();
            Session::flash('success', __('patients.composition.messages.cancellation_submitted'));
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
        foreach ($this->pendingJobCompositions() as $composition) {
            $this->advanceAsyncJob($composition);
        }

        unset($this->paginatedCompositions, $this->hasPendingAsyncJobs);
    }

    /**
     * Whether the list should keep polling.
     */
    #[Computed]
    public function hasPendingAsyncJobs(): bool
    {
        return $this->pendingJobCompositions()->isNotEmpty();
    }

    /**
     * @return Collection<int, Composition>
     */
    private function pendingJobCompositions(): Collection
    {
        return Composition::forPatient($this->patient())
            ->awaitingAsyncJob()
            ->get();
    }

    /**
     * Apply the outcome of one finished job to the local projection.
     *
     * The local state is only moved on DONE. A PENDING job says nothing yet, and a
     * FAILED one means the conclusion is exactly as it was — the failure is recorded so
     * the list can explain why nothing changed.
     */
    private function advanceAsyncJob(Composition $composition): void
    {
        try {
            $status = $this->lifecycle()->jobStatus((string) $composition->asyncJobId);
        } catch (Throwable $exception) {
            Log::error('Failed to read a composition async job', [
                'compositionUuid' => $composition->uuid,
                'error' => $exception->getMessage(),
            ]);

            return;
        }

        if ($status['status'] === CompositionLifecycleService::JOB_FAILED) {
            $composition->update([
                'async_job_status' => CompositionLifecycleService::JOB_FAILED,
                'async_job_error' => implode(' ', $status['errors'])
                    ?: __('patients.composition.errors.async_job_failed'),
            ]);

            return;
        }

        if ($status['status'] !== CompositionLifecycleService::JOB_DONE) {
            return;
        }

        $operation = $composition->asyncJobOperation;

        $composition->update([
            'async_job_status' => CompositionLifecycleService::JOB_DONE,
            'async_job_error' => null,
        ]);

        if ($operation === CompositionAsyncOperation::CANCEL) {
            $composition->update(['status' => CompositionStatus::ENTERED_IN_ERROR->value]);

            return;
        }

        if ($operation === CompositionAsyncOperation::ERLN_RETRY) {
            try {
                $this->lifecycle()->syncIntegration($composition->fresh());
            } catch (Throwable $exception) {
                Log::error('Failed to refresh ERLN status after a retry', [
                    'compositionUuid' => $composition->uuid,
                    'error' => $exception->getMessage(),
                ]);
            }
        }
    }

    // ──────────────────────────────────────────────────────────────────────────
    // ERLN re-send (МВТН only — ТЗ 3.8.2.14)
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Open the ERLN re-send confirmation modal.
     * Only allowed when status = FINAL and erlnStatus = ERROR.
     */
    public function openErlnResendModal(string $compositionUuid): void
    {
        $composition = $this->findLocalComposition($compositionUuid);

        if (!$composition) {
            Session::flash('error', __('patients.composition.errors.not_found'));

            return;
        }

        $response = Gate::inspect('resendErln', $composition);

        if (!$response->allowed()) {
            Session::flash('error', $response->message());

            return;
        }

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
            $job = $this->lifecycle()->resendErln($composition->uuid);

            // The retry is asynchronous: the ERLN status will not change until the job
            // finishes, so the job is recorded and the list poller reports the outcome
            // instead of an immediate refresh that can only show the stale status.
            $composition->update([
                'async_job_id' => $job['id'],
                'async_job_status' => $job['status'] ?? CompositionLifecycleService::JOB_PENDING,
                'async_job_operation' => CompositionAsyncOperation::ERLN_RETRY->value,
                'async_job_error' => null,
            ]);

            $this->closeErlnResendModal();
            Session::flash('success', __('patients.composition.messages.erln_resent_successfully'));
        } catch (Throwable $e) {
            Session::flash('error', $e->getMessage());

            Log::error('Failed to resend МВТН to ERLN', [
                'compositionUuid' => $this->resendingErlnCompositionUuid,
                'error' => $e->getMessage(),
            ]);
        }
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Private helpers
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Re-read ERLN / DRACS status without opening the details (TV 3.8.1.8.5, 3.8.2.10.5).
     */
    public function refreshIntegration(string $compositionUuid): void
    {
        $composition = $this->findLocalComposition($compositionUuid);

        if (!$composition?->hasReadContext) {
            Session::flash('error', __('patients.composition.errors.missing_read_context'));

            return;
        }

        $this->authorize('view', $composition);

        try {
            $this->lifecycle()->syncIntegration($composition);
            Session::flash('success', __('patients.composition.messages.integration_refreshed'));
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
            ->recentlyUpdatedFirst();

        if ($this->filterType) {
            $query->where('type', $this->filterType);
        }

        if ($this->filterStatus) {
            $query->where('status', $this->filterStatus);
        }

        if ($this->filterEncounterId) {
            $query->where('encounter_uuid', $this->filterEncounterId);
        }

        if ($this->filterEpisodeOfCareId) {
            $query->where('episode_of_care_uuid', $this->filterEpisodeOfCareId);
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
        $this->authorize('viewAny', Composition::class);

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
                // The remote result set is paged independently of the local one, so the
                // page currently being viewed decides which slice is worth fetching.
                'offset' => max(0, ($this->getPage() - 1) * $limit) ?: null,
                'limit' => $limit,
            ]);

            $response = EHealth::composition()->search($query);

            $this->syncLocalCompositions($response->getData() ?: ($response->json() ?? []));
        } catch (EHealthConnectionException | EHealthException $exception) {
            $exception->handle('Error searching compositions');
        }
    }

    /**
     * Conclusions of a merged-in unidentified record that may now be clarified.
     *
     * TV 3.8.2.12 — once an unidentified patient has been identified and their records
     * merged, the last SICKNESS МВТН issued to them can be superseded by one for the
     * identified person, which is what creates the ERLN record they never got. Those
     * rows are stored against the preperson, so they never appear in this patient's own
     * list and have to be surfaced explicitly.
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
            ->where('category', CompositionCategory::SICKNESS->value)
            ->final()
            // Only the newest one per merged record is offered: clarifying an already
            // superseded conclusion is not what TV 3.8.2.12 describes.
            ->orderByDesc('event_period_start')
            ->get()
            ->unique('preperson_id')
            ->values();
    }

    /**
     * Upsert compositions returned by the eHealth search into the local DB.
     *
     * The search response is deliberately narrow — it carries no category, author,
     * custodian, focus or validity period — so only the fields it does return are
     * written. Anything already stored from a getComposition call must survive, which is
     * why the payload is filtered rather than passed through wholesale.
     */
    private function syncLocalCompositions(array $compositions): void
    {
        $patient = $this->patient();
        $isPreperson = $patient instanceof Preperson;

        foreach ($compositions as $item) {
            // Identifiers are FHIR `{type, value}` pairs, so the id lives in `identifier.value`.
            $compositionUuid = data_get($item, 'identifier.value');

            if (!$compositionUuid) {
                continue;
            }

            $attributes = array_filter(
                [
                    'person_id' => $isPreperson ? null : $patient->id,
                    'preperson_id' => $isPreperson ? $patient->id : null,
                    'type' => data_get($item, 'type.coding.0.code'),
                    'status' => CompositionStatus::fromEHealth(data_get($item, 'status'))?->value,
                    'title' => data_get($item, 'title'),
                    'encounter_uuid' => data_get($item, 'encounter.value'),
                    'episode_of_care_uuid' => data_get($item, 'episodeOfCare.value'),
                    'composition_date' => data_get($item, 'date'),
                ],
                static fn (mixed $value) => $value !== null
            );

            // The subject is what the read-side endpoints are addressed by, and for a
            // search scoped to this patient it is the patient themselves.
            $attributes['subject_uuid'] = data_get($item, 'subject.value') ?? $this->uuid;

            Composition::updateOrCreate(['uuid' => $compositionUuid], $attributes);
        }
    }

    /**
     * Find a locally stored Composition by UUID.
     */
    private function findLocalComposition(string $uuid): ?Composition
    {
        return Composition::whereUuid($uuid)->first();
    }

    private function lifecycle(): CompositionLifecycleService
    {
        return app(CompositionLifecycleService::class);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Render
    // ──────────────────────────────────────────────────────────────────────────

    public function render(): View
    {
        return view('livewire.composition.composition-index');
    }
}
