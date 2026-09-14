<?php

declare(strict_types=1);

namespace App\Livewire\Composition\Concerns;

use App\Classes\eHealth\EHealth;
use App\Enums\Person\CompositionType;
use App\Enums\Person\EncounterStatus;
use App\Exceptions\EHealth\EHealthConnectionException;
use App\Exceptions\EHealth\EHealthErrorTranslator;
use App\Exceptions\EHealth\EHealthException;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Exceptions\MedicalEvents\CompositionGuardException;
use App\Models\MedicalEvents\Sql\Composition;
use App\Models\Person\Person;
use App\Models\Preperson;
use App\Services\MedicalEvents\CompositionLifecycleService;
use App\Services\SignatureService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Throwable;

/**
 * Shared wizard mechanics for both conclusion types: encounter picker, authentication
 * method, async job, print form and the two KEP steps (create then sign).
 *
 * Each concrete wizard still owns its details form and payload, because those are what
 * the contract treats as two different documents.
 */
trait DrivesCompositionWizard
{
    public const int STEP_ENCOUNTER = 1;

    public const int STEP_AUTH_METHOD = 2;

    public const int STEP_DETAILS = 3;

    public const int STEP_AWAITING_JOB = 4;

    public const int STEP_REVIEW = 5;

    public int $step = self::STEP_ENCOUNTER;

    /** Episode of the chosen encounter; needed to read the conclusion back. */
    #[Locked]
    public ?string $episodeUuid = null;

    public array $authMethods = [];

    /** Shown once the user chooses to proceed without an authentication method. */
    public bool $acknowledgedMissingAuthMethod = false;

    #[Locked]
    public ?string $asyncJobId = null;

    public string $asyncJobStatus = '';

    public array $asyncJobErrors = [];

    #[Locked]
    public ?string $compositionUuid = null;

    public ?array $compositionDetail = null;

    public ?array $integrationData = null;

    public ?string $printFormHtml = null;

    public bool $showPrintModal = false;

    public bool $showSignatureModal = false;

    /**
     * Patient UUID whose encounters may carry this conclusion.
     *
     * A birth conclusion is filed against the newborn, which is not always the card
     * the wizard was opened from (the mother may be), so this is not simply `$this->uuid`.
     */
    abstract protected function encounterSubjectUuid(): string;

    /**
     * Person whose authentication methods inform the conclusion, or null when none apply.
     *
     * An unidentified patient cannot hold any; a birth conclusion uses the mother's.
     */
    abstract protected function authenticationSubjectUuid(): ?string;

    abstract protected function conclusionType(): CompositionType;

    /**
     * Policy ability checked before the create request is sent.
     */
    abstract protected function createAbility(): string;

    /**
     * @return array<string, mixed>
     */
    abstract protected function mapperPayload(string $authorEmployeeUuid): array;

    /**
     * Patient the local projection is stored against.
     *
     * A birth conclusion always belongs to the newborn preperson, even when the wizard
     * started from the mother's card.
     */
    abstract protected function storagePatient(): Person|Preperson;

    /**
     * Rules that make the details step submittable, keyed for the Livewire form.
     *
     * @return array<string, mixed>
     */
    abstract protected function detailsRules(): array;

    /**
     * Encounters the user may build a conclusion on.
     *
     * TV 3.8.1.5.1 / 3.8.2.5.1 restrict the choice to encounters the user performed
     * themselves, and eHealth additionally rejects anything that is not finished.
     *
     * @return Collection<int, array<string, mixed>>
     */
    #[Computed]
    public function availableEncounters(): Collection
    {
        $authorUuid = $this->authorEmployeeUuid();
        $subjectUuid = $this->encounterSubjectUuid();

        if ($authorUuid === null || $subjectUuid === '') {
            return collect();
        }

        try {
            $params = array_filter(['managing_organization_id' => legalEntity()?->uuid]);
            $encounters = EHealth::encounter()
                ->getBySearchParams($subjectUuid, $params)
                ->validate();
        } catch (EHealthConnectionException | EHealthException $exception) {
            $exception->handle('Error loading encounters for a medical conclusion');

            return collect();
        }

        return collect($encounters)
            ->filter(static fn (array $encounter) => filled(data_get($encounter, 'uuid')))
            ->filter(static fn (array $encounter) => data_get($encounter, 'status') === EncounterStatus::FINISHED->value)
            ->filter(static fn (array $encounter) => data_get($encounter, 'performer.identifier.value') === $authorUuid)
            ->sortByDesc(static fn (array $encounter) => data_get($encounter, 'period.start'))
            ->values();
    }

    public function selectEncounter(string $encounterUuid): void
    {
        $encounter = $this->availableEncounters
            ->firstWhere('uuid', $encounterUuid);

        if (!$encounter) {
            Session::flash('error', __('patients.composition.errors.encounter_not_selectable'));

            return;
        }

        $this->form->encounterUuid = $encounterUuid;
        $this->episodeUuid = data_get($encounter, 'episode.identifier.value');

        $this->loadAuthMethods();
        $this->step = self::STEP_AUTH_METHOD;
    }

    public function loadAuthMethods(): void
    {
        $this->authMethods = [];
        $subjectUuid = $this->authenticationSubjectUuid();

        if ($subjectUuid === null || $subjectUuid === '') {
            return;
        }

        try {
            $this->authMethods = EHealth::person()
                ->getAuthMethods($subjectUuid)
                ->getData();
        } catch (EHealthConnectionException | EHealthException $exception) {
            Log::error('Failed to load authentication methods for a medical conclusion', [
                'focus' => $subjectUuid,
                'error' => $exception->getMessage(),
            ]);

            Session::flash('error', __('patients.composition.errors.auth_methods_failed'));
        }
    }

    /**
     * Adopt one of the authentication methods eHealth returned for this person.
     *
     * The list is reloaded rather than trusted from component state, because the action
     * is reachable with any UUID and eHealth would otherwise be handed an INFORM_WITH
     * that does not belong to the patient.
     */
    public function selectAuthMethod(string $methodUuid): void
    {
        if ($this->authMethods === []) {
            $this->loadAuthMethods();
        }

        if (!$this->isKnownAuthMethod($methodUuid)) {
            $this->addError('form.informWithUuid', __('patients.composition.errors.auth_method_not_offered'));

            Session::flash('error', __('patients.composition.errors.auth_method_not_offered'));

            return;
        }

        $this->form->informWithUuid = $methodUuid;
        $this->acknowledgedMissingAuthMethod = false;
        $this->step = self::STEP_DETAILS;

        if (method_exists($this, 'applyDefaultPeriodDates')) {
            $this->applyDefaultPeriodDates();
        }
    }

    /**
     * Whether the UUID is one of the methods eHealth listed for the relevant person.
     */
    protected function isKnownAuthMethod(string $methodUuid): bool
    {
        // The list is taken straight from the response, so an entry may still carry the
        // raw eHealth `id` rather than the renamed `uuid` — the view reads both too.
        return collect($this->authMethods)
            ->map(static fn (mixed $method): mixed => data_get($method, 'uuid') ?? data_get($method, 'id'))
            ->filter()
            ->contains($methodUuid);
    }

    /**
     * Continue without informing the patient by SMS (TV 3.8.1.4.4, 3.8.2.4.4).
     *
     * SMS with the conclusion number is sent by eHealth only after the unsigned
     * composition is created with INFORM_WITH — not when the method is chosen here.
     */
    public function skipAuthMethod(): void
    {
        $this->form->informWithUuid = null;
        $this->acknowledgedMissingAuthMethod = true;
        $this->step = self::STEP_DETAILS;

        if (method_exists($this, 'applyDefaultPeriodDates')) {
            $this->applyDefaultPeriodDates();
        }
    }

    /**
     * Check everything that must hold, then ask for the author's KEP.
     *
     * createComposition transports the conclusion as a detached signature, so the
     * signature is part of creating it rather than a later step: nothing is sent until
     * {@see submitComposition()} has something signed to send.
     */
    public function reviewDetails(): void
    {
        $this->authorize($this->createAbility(), Composition::class);

        try {
            $this->form->validate($this->detailsRules());
            $this->assertSubmissionAllowed();
        } catch (ValidationException $exception) {
            $this->setErrorBag($exception->validator->getMessageBag());

            return;
        } catch (CompositionGuardException $exception) {
            $this->reportGuardFailure($exception);

            return;
        }

        if ($this->authorEmployeeUuid() === null) {
            Session::flash('error', __('patients.composition.errors.author_not_found'));

            return;
        }

        $this->form->resetSigningFields();
        $this->showSignatureModal = true;
    }

    /**
     * Sign the mapped conclusion and send it to eHealth (TV 3.8.1.5, 3.8.2.5).
     *
     * Every rule checked in {@see reviewDetails()} is checked again here. This action is
     * a public Livewire endpoint of its own, so the earlier pass is a UX affordance, not
     * a guarantee that it ran.
     */
    public function submitComposition(): void
    {
        $this->authorize($this->createAbility(), Composition::class);

        try {
            $this->form->validate(array_merge($this->detailsRules(), $this->form->signingRules()));
            $this->assertSubmissionAllowed();
        } catch (ValidationException $exception) {
            $this->setErrorBag($exception->validator->getMessageBag());

            return;
        } catch (CompositionGuardException $exception) {
            $this->reportGuardFailure($exception);

            return;
        }

        $authorUuid = $this->authorEmployeeUuid();

        if ($authorUuid === null) {
            Session::flash('error', __('patients.composition.errors.author_not_found'));

            return;
        }

        try {
            // The payload is deliberately not logged: it is the patient's medical record.
            $signedContent = app(SignatureService::class)->signData(
                $this->mapperPayload($authorUuid),
                $this->form->password,
                $this->form->knedp,
                $this->form->keyContainerUpload,
                Auth::user()->party->taxId
            );

            $job = $this->lifecycle()->create($signedContent);

            $this->asyncJobId = $job['id'];
            $this->asyncJobStatus = (string) ($job['status'] ?? CompositionLifecycleService::JOB_PENDING);
            $this->asyncJobErrors = [];
            $this->showSignatureModal = false;
            $this->form->resetSigningFields();
            $this->step = self::STEP_AWAITING_JOB;
        } catch (EHealthResponseException $exception) {
            $details = $exception->getDetails();
            $errText = data_get($details, 'error.message')
                ?? data_get($details, 'details.errorMessage')
                ?? data_get($details, 'description')
                ?? $exception->getMessage();

            $exception->handle(
                'Failed to submit a medical conclusion',
                EHealthErrorTranslator::translate((string) $errText)
            );
        } catch (EHealthConnectionException | EHealthException $exception) {
            $exception->handle('Failed to submit a medical conclusion');
        } catch (Throwable $exception) {
            Session::flash('error', $exception->getMessage());

            Log::error('Failed to submit a medical conclusion', ['error' => $exception->getMessage()]);
        }
    }

    /**
     * Type-specific rules that the UI also expresses but cannot enforce.
     *
     * @throws CompositionGuardException
     */
    protected function assertSubmissionAllowed(): void
    {
        // Overridden by the conclusion types that have extra rules.
    }

    /**
     * Surface a refused rule both next to the form and as a page-level message.
     *
     * The error bag keeps it visible where the user is working; the flash covers the
     * case where the step has already been left behind.
     */
    protected function reportGuardFailure(CompositionGuardException $exception): void
    {
        $this->addError('form.guard', $exception->getMessage());

        Session::flash('error', $exception->getMessage());
    }

    /**
     * Poll the async job until eHealth finishes the current request.
     *
     * The same poller covers create (TV 3.8.1.5.3 / 3.8.2.7) and the later sign, which
     * also returns a job. After a sign, the conclusion already exists locally so the
     * details are refreshed rather than resolved from scratch.
     */
    public function pollAsyncJob(): void
    {
        if (!$this->asyncJobId || $this->asyncJobStatus === CompositionLifecycleService::JOB_DONE) {
            return;
        }

        try {
            $status = $this->lifecycle()->jobStatus($this->asyncJobId);
        } catch (Throwable $exception) {
            Log::error('Failed to read the conclusion async job', ['error' => $exception->getMessage()]);

            return;
        }

        $this->asyncJobStatus = $status['status'];

        if ($status['status'] === CompositionLifecycleService::JOB_FAILED) {
            $this->asyncJobErrors = $status['errors'];

            return;
        }

        if ($status['status'] !== CompositionLifecycleService::JOB_DONE) {
            return;
        }

        if ($this->compositionUuid === null) {
            $this->compositionUuid = $status['compositionUuid']
                ?? $this->lifecycle()->resolveCreatedComposition(
                    [],
                    $this->encounterSubjectUuid(),
                    $this->form->encounterUuid,
                    $this->conclusionType()
                );
        }

        if ($this->compositionUuid === null) {
            $this->asyncJobErrors = [__('patients.composition.errors.created_not_found')];

            return;
        }

        $this->loadCompositionDetail();
        $this->step = self::STEP_REVIEW;
    }

    public function loadCompositionDetail(): void
    {
        if (!$this->compositionUuid || !$this->episodeUuid) {
            return;
        }

        try {
            $this->compositionDetail = $this->lifecycle()->fetchDetails(
                $this->encounterSubjectUuid(),
                $this->compositionUuid,
                $this->episodeUuid,
                $this->form->encounterUuid
            );

            $composition = $this->lifecycle()->storeLocal(
                $this->compositionDetail,
                $this->storagePatient(),
                $this->episodeUuid,
                $this->asyncJobId
            );

            if ($composition !== null) {
                try {
                    $this->integrationData = $this->lifecycle()->syncIntegration($composition);
                } catch (EHealthConnectionException | EHealthException) {
                    $this->integrationData = data_get($composition->data, '_integration');
                }
            }
        } catch (EHealthConnectionException | EHealthException $exception) {
            $exception->handle('Error reading the created medical conclusion');
        }
    }

    public function loadPrintForm(): void
    {
        if (!$this->compositionUuid || !$this->episodeUuid) {
            return;
        }

        try {
            $templateId = $this->conclusionType()->printTemplateId();
            $response = EHealth::composition()->getPrintForm(
                $this->encounterSubjectUuid(),
                $this->compositionUuid,
                $this->episodeUuid,
                $this->form->encounterUuid,
                $templateId
            );

            $this->printFormHtml = $response->body();
            $this->showPrintModal = true;
        } catch (EHealthConnectionException | EHealthException $exception) {
            Session::flash('error', __('patients.composition.errors.print_form_failed'));

            Log::error('Failed to load the conclusion print form', [
                'composition' => $this->compositionUuid,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    public function closePrintModal(): void
    {
        $this->showPrintModal = false;
        $this->printFormHtml = null;
    }

    public function openSigningModal(): void
    {
        $this->form->resetSigningFields();
        $this->showSignatureModal = true;
    }

    public function sign(): void
    {
        $composition = Composition::whereUuid($this->compositionUuid)->first();

        if (!$composition) {
            Session::flash('error', __('patients.composition.errors.not_found'));

            return;
        }

        $this->authorize('sign', $composition);

        try {
            $this->form->validate($this->form->signingRules());
        } catch (ValidationException $exception) {
            $this->setErrorBag($exception->validator->getMessageBag());

            return;
        }

        try {
            $signedContent = app(SignatureService::class)->signData(
                $this->compositionDetail ?? [],
                $this->form->password,
                $this->form->knedp,
                $this->form->keyContainerUpload,
                Auth::user()->party->taxId
            );

            $job = $this->lifecycle()->sign($composition->uuid, $signedContent);

            $composition->update(['async_job_id' => $job['id']]);

            $this->showSignatureModal = false;
            $this->form->resetSigningFields();
            $this->asyncJobId = $job['id'];
            $this->asyncJobStatus = (string) ($job['status'] ?? CompositionLifecycleService::JOB_PENDING);
            $this->asyncJobErrors = [];
            $this->step = self::STEP_AWAITING_JOB;

            Session::flash('success', __('patients.composition.messages.signature_submitted'));
        } catch (Throwable $exception) {
            Session::flash('error', $exception->getMessage());

            Log::error('Failed to sign a medical conclusion', [
                'composition' => $this->compositionUuid,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * Shared wizard state that both conclusions reset. Each child then restores the
     * fields that are unique to its form.
     *
     * @param  list<string>  $extra
     */
    protected function resetWizard(array $extra = []): void
    {
        $this->form->resetSigningFields();
        $this->reset(array_merge([
            'step',
            'episodeUuid',
            'authMethods',
            'acknowledgedMissingAuthMethod',
            'asyncJobId',
            'asyncJobStatus',
            'asyncJobErrors',
            'compositionUuid',
            'compositionDetail',
            'integrationData',
            'printFormHtml',
            'showPrintModal',
            'showSignatureModal',
        ], $extra));
    }

    /**
     * Employee the conclusion is authored as, restricted to the role TV 3.8 allows for
     * this conclusion type in the current legal entity.
     */
    protected function authorEmployeeUuid(): ?string
    {
        return Auth::user()?->getCompositionAuthorEmployee($this->conclusionType())?->uuid;
    }

    protected function lifecycle(): CompositionLifecycleService
    {
        return app(CompositionLifecycleService::class);
    }
}
