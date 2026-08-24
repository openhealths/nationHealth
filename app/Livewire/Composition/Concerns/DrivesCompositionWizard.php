<?php

declare(strict_types=1);

namespace App\Livewire\Composition\Concerns;

use App\Classes\eHealth\EHealth;
use App\Enums\Person\CompositionType;
use App\Enums\Person\EncounterStatus;
use App\Exceptions\EHealth\EHealthConnectionException;
use App\Exceptions\EHealth\EHealthException;
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
            Session::flash('error', __('compositions.errors.encounter_not_selectable'));

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

            Session::flash('error', __('compositions.errors.auth_methods_failed'));
        }
    }

    public function selectAuthMethod(string $methodUuid): void
    {
        $this->form->informWithUuid = $methodUuid;
        $this->acknowledgedMissingAuthMethod = false;
        $this->step = self::STEP_DETAILS;
    }

    /**
     * Continue without informing the patient by SMS (TV 3.8.1.4.4, 3.8.2.4.4).
     */
    public function skipAuthMethod(): void
    {
        $this->form->informWithUuid = null;
        $this->acknowledgedMissingAuthMethod = true;
        $this->step = self::STEP_DETAILS;
    }

    public function reviewDetails(): void
    {
        $this->authorize($this->createAbility(), Composition::class);

        try {
            $this->form->validate($this->detailsRules());
        } catch (ValidationException $exception) {
            $this->setErrorBag($exception->validator->getMessageBag());

            return;
        }

        $authorUuid = $this->authorEmployeeUuid();

        if ($authorUuid === null) {
            Session::flash('error', __('compositions.errors.author_not_found'));

            return;
        }

        try {
            $payload = $this->mapperPayload($authorUuid);
            Log::info('Submitting medical conclusion payload', ['payload' => $payload]);

            $job = $this->lifecycle()->create($payload);

            $this->asyncJobId = $job['id'];
            $this->asyncJobStatus = (string) ($job['status'] ?? CompositionLifecycleService::JOB_PENDING);
            $this->asyncJobErrors = [];
            $this->showSignatureModal = false;
            $this->step = self::STEP_AWAITING_JOB;
        } catch (EHealthResponseException $exception) {
            $details = $exception->getDetails();
            $errText = data_get($details, 'error.message')
                ?? data_get($details, 'details.errorMessage')
                ?? data_get($details, 'description')
                ?? $exception->getMessage();

            $exception->handle('Failed to submit a medical conclusion', $errText);
        } catch (EHealthConnectionException | EHealthException $exception) {
            $exception->handle('Failed to submit a medical conclusion');
        } catch (Throwable $exception) {
            Session::flash('error', $exception->getMessage());

            Log::error('Failed to submit a medical conclusion', ['error' => $exception->getMessage()]);
        }
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
            $this->asyncJobErrors = [__('compositions.errors.created_not_found')];

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
            $templateId = $this->conclusionType() === CompositionType::NEWBORN ? '1000' : '1001';
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
            Session::flash('error', __('compositions.errors.print_form_failed'));

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
            Session::flash('error', __('compositions.errors.not_found'));

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

            Session::flash('success', __('compositions.messages.signature_submitted'));
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

    protected function authorEmployeeUuid(): ?string
    {
        return Auth::user()?->getCompositionAuthorEmployee()?->uuid;
    }

    protected function lifecycle(): CompositionLifecycleService
    {
        return app(CompositionLifecycleService::class);
    }
}
