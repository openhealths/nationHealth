<?php

declare(strict_types=1);

namespace App\Livewire\Person\Records;

use App\Classes\eHealth\EHealth;
use App\Enums\Person\CompositionStatus;
use App\Enums\Person\CompositionType;
use App\Exceptions\EHealth\EHealthConnectionException;
use App\Exceptions\EHealth\EHealthException;
use App\Livewire\Composition\Forms\CompositionCancellationForm;
use App\Models\MedicalEvents\Sql\Composition;
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

    /**
     * Async job status reported by eHealth while a request is still being processed.
     */
    private const string JOB_STATUS_PENDING = 'PENDING';

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
            $templateId = $composition->isNewborn ? '1000' : '1001';
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

            EHealth::composition()->cancel($composition->uuid, ['data' => $signedContent]);

            // eHealth processes the cancellation asynchronously, so the conclusion is not in
            // error yet. Record the job and let the poller move the status once it is done.
            $composition->update(['async_job_status' => self::JOB_STATUS_PENDING]);

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
            EHealth::composition()->resendErln($composition->uuid);

            try {
                $this->lifecycle()->syncIntegration($composition->fresh());
            } catch (Throwable) {
                // The resend itself is asynchronous; failing to refresh the cached
                // status must not look like the retry never left.
            }

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
        try {
            // `subject` and `focus` are mutually exclusive, so searching by an explicit
            // focus replaces the implicit search by the patient being viewed.
            $searchByFocus = filled($this->filterSectionFocusUuid);

            $query = array_filter([
                'subject' => $searchByFocus ? null : $this->uuid,
                'focus' => $searchByFocus ? $this->filterSectionFocusUuid : null,
                'type' => $this->filterType ?: null,
                'status' => $this->filterStatus ?: null,
                'encounter' => $this->filterEncounterId ?: null,
                'episodeOfCare' => $this->filterEpisodeOfCareId ?: null,
            ]);

            $response = EHealth::composition()->search($query);

            $this->syncLocalCompositions($response->getData() ?: ($response->json() ?? []));
        } catch (EHealthConnectionException | EHealthException $exception) {
            $exception->handle('Error searching compositions');
        }
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
