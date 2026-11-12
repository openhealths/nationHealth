<?php

declare(strict_types=1);

namespace App\Livewire\CarePlan;

use App\Classes\eHealth\EHealth;
use App\Classes\eHealth\EHealthResponse;
use App\Enums\CarePlanApprovalCreateOutcome;
use App\Enums\CarePlanApprovalJobOutcome;
use App\Enums\CarePlanStatus;
use App\Enums\JobStatus;
use App\Enums\Person\AuthenticationMethod;
use App\Exceptions\EHealth\EHealthJobTimeoutException;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Exceptions\EHealth\EHealthValidationException;
use App\Jobs\RemoteEHealthLinksProcessing;
use App\Livewire\CarePlan\Forms\CarePlanApprovalForm;
use App\Models\CarePlan;
use App\Models\LegalEntity;
use App\Models\MedicalEvents\Sql\Approval as PatientApproval;
use App\Models\Person\Person;
use App\Models\User;
use App\Repositories\MedicalEvents\Repository as MedicalEventsRepository;
use App\Repositories\Repository;
use App\Services\MedicalEvents\MedicalRequestOwnership;
use App\Services\MedicalEvents\PatientApprovalService;
use App\Traits\FormTrait;
use App\Traits\InteractsWithApprovals;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Exception;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;
use RuntimeException;
use Throwable;

class CarePlanApprovals extends Component
{
    use FormTrait;
    use InteractsWithApprovals;

    #[Locked]
    public int $carePlanId = 0;

    #[Locked]
    public string $carePlanUuid = '';

    #[Locked]
    public string $patientUuid = '';

    #[Locked]
    public array $approvals = [];

    #[Locked]
    public ?string $errorMessage = null;

    #[Locked]
    public bool $isLoading = false;

    public ?string $selectedAuthMethodUuid = null;

    #[Locked]
    public array $authMethods = [];

    /** Active employees of the current legal entity for the dropdown. */
    #[Locked]
    public array $employees = [];

    // For creating new approval
    public CarePlanApprovalForm $newApproval;

    #[Locked]
    public bool $isReadOnly = false;

    #[Locked]
    public string $statusLabel = '';

    /** INPATIENT plans at this legal entity skip patient SMS. */
    #[Locked]
    public bool $skipsPatientOtp = false;

    public function mount(LegalEntity $legalEntity, CarePlan $carePlan, ?string $actingEmployeeUuid = null): void
    {
        if (Auth::user()->cannot('view', $carePlan)) {
            $this->isReadOnly = true;
            Session::flash('error', __('care-plan.approval_access_denied'));

            return;
        }

        $this->carePlanId = $carePlan->id;
        $this->carePlanUuid = $carePlan->uuid ?? '';
        $this->patientUuid = $carePlan->person?->uuid ?? '';
        $this->isReadOnly = CarePlanStatus::fromStored($carePlan->status)->isTerminal();
        $this->statusLabel = CarePlanStatus::labelFor($carePlan->status);
        $this->skipsPatientOtp = $carePlan->skipsPatientOtp();
        $this->fetchApprovals();

        // Load active employees for the dropdown, filtered by the current active legal entity.
        // We must use the active legal entity (not the care plan's owner), because eHealth validates
        // that the granted employee belongs to the requesting clinic.
        $legalEntityId = legalEntity()?->id ?? $legalEntity->id;
        if ($legalEntityId) {
            $this->employees = $this->approvalEmployees($legalEntityId);

            // Document forms pass their selected physician so receipt never silently changes the actor.
            $writerUuid = Auth::user()?->getCarePlanWriterEmployee($carePlan->termsOfServiceCode(), $actingEmployeeUuid)?->uuid;
            if ($writerUuid && collect($this->employees)->contains(fn (array $employee): bool => $employee['uuid'] === $writerUuid)) {
                $this->newApproval->employee_uuid = $writerUuid;
            } elseif ($actingEmployeeUuid === null && count($this->employees) === 1) {
                $this->newApproval->employee_uuid = $this->employees[0]['uuid'];
            }
        }

    }

    public function loadAuthMethods(): void
    {
        // A denied mount leaves the plan id unset, so skip deferred initialization before resolving it.
        if ($this->isReadOnly || !$this->guardApprovalAccess($this->currentCarePlan())) {
            return;
        }
        if ($this->skipsPatientOtp) {
            return;
        }
        if ($this->authMethods === []) {
            $methods = EHealth::person()->getAuthMethods($this->patientUuid)->validate();
            $this->authMethods = app(PatientApprovalService::class)
                ->enrichAuthenticationMethods($methods, $this->currentCarePlan()->person);
        }
        if (empty($this->selectedAuthMethodUuid)) {
            $method = collect($this->authMethods)->firstWhere('type', 'OTP') ?? ($this->authMethods[0] ?? null);
            $this->selectedAuthMethodUuid = $method['id'] ?? ($method['uuid'] ?? null);
        }
    }

    /**
     * Sync from eHealth and refresh the local approvals list.
     */
    #[On('refreshApprovals')]
    public function fetchApprovals(bool $notifyErrors = true): void
    {
        $this->isLoading = true;

        try {
            $carePlan = $this->currentCarePlan();
            $this->skipsPatientOtp = $carePlan->skipsPatientOtp();
            $this->approvals = MedicalEventsRepository::approval()->localForCarePlan($carePlan)
                ->map(function (PatientApproval $approval): array {
                    $link = $approval->ehealthLinks->sortByDesc('id')->first();
                    $shared = $approval->approvableType === Person::class;

                    return [
                        'uuid' => $approval->uuid,
                        'is_verified' => $approval->getAttribute('is_verified'),
                        'expires_at' => $approval->expiresAt?->toIso8601String(),
                        'created_at' => $approval->createdAt?->toIso8601String(),
                        'granted_to' => ['value' => $approval->grantedTo?->value],
                        'granted_to_details' => $approval->grantedToDetails,
                        'sharedCarePlanApproval' => $shared,
                        'requestPending' => !$shared && $link !== null && !in_array($link->status, [JobStatus::PROCESSED->value, JobStatus::FAILED->value], true),
                    ];
                })->all();
        } catch (Exception $e) {
            if (!$notifyErrors) {
                throw $e;
            }
            Log::error('CarePlanApprovals: failed to fetch: ' . $e->getMessage());
            Session::flash('error', __('care-plan.approvals_fetch_error'));
        } finally {
            $this->isLoading = false;
        }
    }

    /**
     * Submit an approval request and distinguish confirmation from a pending job.
     */
    public function createApproval(): void
    {
        $this->errorMessage = null;

        $this->newApproval->validate();

        $carePlan = $this->currentCarePlan();
        if (!$this->guardApprovalAccess($carePlan)) {
            return;
        }
        $this->isReadOnly = CarePlanStatus::fromStored($carePlan->status)->isTerminal();

        if ($this->isReadOnly) {
            $this->errorMessage = __('care-plan.cannot_grant_terminal', [
                'status' => CarePlanStatus::labelFor($carePlan->status),
            ]);
            Session::flash('error', $this->errorMessage);

            return;
        }

        try {
            $this->loadAuthMethods();
            if (!$this->skipsPatientOtp) {
                $this->currentAuthMethod = $this->selectedApprovalAuthMethod($this->selectedAuthMethodUuid ?? '');
            }

            $result = $this->createCarePlanApproval(
                carePlan: $carePlan,
                patientUuid: $this->patientUuid,
                employeeUuid: $this->newApproval->employee_uuid,
                accessLevel: $carePlan->resolveAccessLevel(),
                authorizeWith: $this->skipsPatientOtp ? null : ($this->selectedAuthMethodUuid ?: null),
                user: Auth::user(),
                bearerToken: Session::get(config('ehealth.api.oauth.bearer_token')),
            );

            if (($result['outcome']->value === 'async')) {
                $this->pollingLinkId = $result['pollingLinkId'];
                $this->approvalId = $result['approvalId'];
                $this->isPolling = true;
                Session::flash('info', __('care-plan.approval_processing'));

                return;
            }

            if (in_array($result['outcome']->value, ['otp_required', 'offline_required'], true)) {
                $this->approvalId = $result['approvalId'];
                $this->currentAuthMethod = $result['authMethod'] ?? $this->currentAuthMethod;
                $this->openAuthModal();

                return;
            }

            Session::flash('success', $this->skipsPatientOtp
                ? __('care-plan.approval_inpatient_granted')
                : __('care-plan.approval_created'));
            $this->reset('newApproval');
            $this->fetchApprovals();
            $this->dispatch('care-plan-approvals-changed');
        } catch (ValidationException $e) {
            $this->errorMessage = $e->validator->errors()->first();
            Session::flash('error', $this->errorMessage);
            $this->setErrorBag($e->validator->getMessageBag());
        } catch (EHealthValidationException|EHealthResponseException $e) {
            Log::error('CarePlanApprovals: eHealth error: ' . $e->getMessage());
            $this->errorMessage = $e instanceof EHealthValidationException
                ? $e->getFormattedMessage()
                : __('Помилка від ЕСОЗ: ') . $e->getMessage();
            Session::flash('error', $this->errorMessage);
        } catch (Exception $e) {
            Log::error('CarePlanApprovals: failed to create: ' . $e->getMessage());
            $this->errorMessage = __('care-plan.approval_create_error');
            Session::flash('error', $this->errorMessage);
        }
    }

    /**
     * Called by wire:poll.2s while $isPolling === true.
     */
    public function checkApprovalJobStatus(): void
    {
        if (!$this->isPolling || !$this->pollingLinkId) {
            return;
        }

        $status = $this->resolveCarePlanApprovalJob($this->pollingLinkId);

        if (($status['outcome']->value === 'pending')) {
            return;
        }

        $this->isPolling = false;
        $this->pollingLinkId = null;

        if (($status['outcome']->value === 'failed')) {
            $this->errorMessage = $status['errorMessage'] ?: __('care-plan.approval_create_error');
            Session::flash('error', $this->errorMessage);

            return;
        }

        if ($status['approvalId']) {
            $this->approvalId = $status['approvalId'];
        }

        if (in_array($status['outcome']->value, ['otp_required', 'offline_required'], true)) {
            $this->currentAuthMethod = $status['authMethod'] ?? $this->currentAuthMethod;
            $this->openAuthModal();

            return;
        }

        Session::flash('success', $this->skipsPatientOtp
            ? __('care-plan.approval_inpatient_granted')
            : __('care-plan.approval_created'));
        $this->errorMessage = null;
        $this->reset('newApproval');
        $this->fetchApprovals();
        $this->dispatch('care-plan-approvals-changed');
    }

    public function verifyExistingApproval(string $approvalUuid): void
    {
        if ($this->guardReadOnlyApprovals()) {
            return;
        }

        $approval = app(MedicalRequestOwnership::class)->approvalForCarePlan($this->currentCarePlan(), $approvalUuid);
        if ($this->skipsPatientOtp) {
            $this->confirmInpatientApproval($approvalUuid);

            return;
        }

        $link = MedicalEventsRepository::approval()->creationLinkFor($approval);
        if ($link !== null) {
            $this->pollingLinkId = $link->id;
            $this->isPolling = true;
            $this->checkApprovalJobStatus();

            return;
        }
        try {
            $result = $this->pendingCarePlanApprovalConfirmation($this->patientUuid, $approvalUuid, $approval->authorizeWith);
            $this->approvalId = $result['approvalId'];
            if (($result['outcome']->value === 'granted')) {
                $this->fetchApprovals();
                Session::flash('success', __('care-plan.approval_verified'));

                return;
            }
            $this->currentAuthMethod = $result['authMethod'];
            $this->openAuthModal();
        } catch (Throwable $exception) {
            Session::flash('error', __('care-plan.approval_result_unavailable'));
        }
    }

    public function resumeApprovalRequest(string $approvalUuid): void
    {
        if (!$this->guardApprovalAccess($this->currentCarePlan())) {
            return;
        }
        $approval = app(MedicalRequestOwnership::class)->approvalForCarePlan($this->currentCarePlan(), $approvalUuid);
        $link = MedicalEventsRepository::approval()->creationLinkFor($approval);
        if ($link === null) {
            return;
        }
        if ($this->skipsPatientOtp && $link->status === 'PROCESSED') {
            $this->confirmInpatientApproval($approvalUuid);

            return;
        }
        $this->pollingLinkId = $link->id;
        $this->isPolling = true;
        $this->checkApprovalJobStatus();
    }

    public function recreateApproval(string $oldApprovalUuid): void
    {
        $this->errorMessage = null;

        if ($this->guardReadOnlyApprovals()) {
            return;
        }

        try {
            $oldApproval = app(MedicalRequestOwnership::class)
                ->approvalForCarePlan($this->currentCarePlan(), $oldApprovalUuid)
                ->loadMissing(['grantedTo', 'createdBy']);
            if (Auth::user()->cannot('cancel', [$oldApproval, legalEntity()])) {
                Session::flash('error', __('approval.messages.cancel_forbidden'));

                return;
            }
            $this->loadAuthMethods();
            $carePlan = $this->currentCarePlan();
            $employeeUuid = $oldApproval->grantedTo?->value
                ?? Auth::user()?->getCarePlanWriterEmployee($carePlan->termsOfServiceCode())?->uuid;

            if (!$employeeUuid) {
                Session::flash('error', __('care-plan.employee_not_found'));

                return;
            }

            if (!$this->skipsPatientOtp) {
                $this->currentAuthMethod = $this->selectedApprovalAuthMethod($this->selectedAuthMethodUuid ?? '');
            }
            Repository::carePlan()->assertApprovalRecipient($employeeUuid, legalEntity()->id);
            try {
                app(PatientApprovalService::class)->cancel($oldApproval);
            } catch (EHealthResponseException $e) {
                if ($e->getCode() !== 404) {
                    throw $e;
                }
            }

            $result = $this->createCarePlanApproval(
                carePlan: $carePlan,
                patientUuid: $this->patientUuid,
                employeeUuid: $employeeUuid,
                accessLevel: $carePlan->resolveAccessLevel(),
                authorizeWith: $this->skipsPatientOtp ? null : ($this->selectedAuthMethodUuid ?: null),
                user: Auth::user(),
                bearerToken: Session::get(config('ehealth.api.oauth.bearer_token')),
            );

            if (($result['outcome']->value === 'async')) {
                $this->pollingLinkId = $result['pollingLinkId'];
                $this->approvalId = $result['approvalId'];
                $this->isPolling = true;
                Session::flash('info', __('care-plan.approval_processing'));

                return;
            }

            if (in_array($result['outcome']->value, ['otp_required', 'offline_required'], true)) {
                $this->approvalId = $result['approvalId'];
                $this->currentAuthMethod = $result['authMethod'] ?? $this->currentAuthMethod;
                $this->openAuthModal();

                return;
            }

            Session::flash('success', $this->skipsPatientOtp
                ? __('care-plan.approval_inpatient_granted')
                : __('care-plan.approval_created'));
            $this->fetchApprovals();
            $this->dispatch('care-plan-approvals-changed');
        } catch (Exception $e) {
            Log::error('CarePlanApprovals: failed to recreate: ' . $e->getMessage());
            $this->errorMessage = __('Помилка при перестворенні: ') . $e->getMessage();
            Session::flash('error', $this->errorMessage);
        }
    }

    /**
     * INPATIENT same-org approvals are auto-verified in eHealth — no SMS code exists.
     */
    protected function confirmInpatientApproval(string $approvalUuid): void
    {
        $this->errorMessage = null;
        $this->approvalId = $approvalUuid;

        try {
            $response = $this->confirmCarePlanApprovalWithoutOtp($this->patientUuid, $approvalUuid);
            if (!$response->successful()) {
                throw new EHealthResponseException($response);
            }
        } catch (EHealthValidationException $exception) {
            $this->errorMessage = $exception->getTranslatedMessage();
            Session::flash('error', $this->errorMessage);

            return;
        } catch (Throwable $exception) {
            Log::warning('CarePlanApprovals: inpatient confirmation failed', ['exception_type' => $exception::class]);
            $this->errorMessage = __('care-plan.approval_verify_error');
            Session::flash('error', $this->errorMessage);

            return;
        }

        $this->isPolling = false;
        $this->pollingLinkId = null;
        $this->fetchApprovals();
        $this->dispatch('care-plan-approvals-changed');
        Session::flash('success', __('care-plan.approval_inpatient_granted'));
    }

    public function verify(): void
    {
        if ($this->isSmsResending) {
            Session::flash('info', __('care-plan.sms_resend_processing'));

            return;
        }
        if (!$this->guardApprovalAccess($this->currentCarePlan())) {
            return;
        }
        app(MedicalRequestOwnership::class)->approvalForCarePlan($this->currentCarePlan(), $this->approvalId ?? '');
        try {
            $this->validate($this->approvalVerificationRules(), $this->approvalVerificationMessages());
        } catch (ValidationException $exception) {
            Session::flash('error', $exception->validator->errors()->first());
            $this->setErrorBag($exception->validator->getMessageBag());

            return;
        }

        try {
            $this->verifyCarePlanApproval(
                $this->patientUuid,
                $this->approvalId,
                $this->isOfflineAuthMethod() ? null : (int) $this->verificationCode,
            );
        } catch (EHealthValidationException|EHealthResponseException $e) {
            Log::error('CarePlanApprovals: failed to verify: ' . $e->getMessage());

            if ($e->getCode() === 404 || str_contains($e->getMessage(), '404')) {
                Repository::carePlan()->removeApproval($this->currentCarePlan(), $this->approvalId);
                Session::flash('error', __('care-plan.approval_expired_404'));
                $this->closeAuthModal();
                $this->fetchApprovals();

                return;
            }

            $msg = $e instanceof EHealthValidationException
                ? $e->getFormattedMessage()
                : __('Помилка від ЕСОЗ: ') . $e->getMessage();
            Session::flash('error', $msg);

            return;
        } catch (Exception $e) {
            Log::warning('Care plan approval confirmation failed', ['exceptionType' => $e::class]);
            Session::flash('error', __('care-plan.approval_verify_error'));

            return;
        }
        $this->errorMessage = null;
        $this->resetValidation('verificationCode');
        $this->closeAuthModal();
        $this->reset('newApproval');
        try {
            $this->fetchApprovals(notifyErrors: false);
            $this->dispatch('care-plan-approvals-changed');
        } catch (Throwable $exception) {
            Log::warning('Verified care plan approval list could not be refreshed', ['exceptionType' => $exception::class]);
            Session::flash('warning', __('care-plan.approval_verified_refresh_pending'));

            return;
        }
        Session::flash('success', __('care-plan.approval_verified'));
    }

    protected function carePlanForSms(): CarePlan
    {
        return $this->currentCarePlan();
    }

    protected function onSmsApprovalAlreadyGranted(): void
    {
        $this->fetchApprovals();
        $this->dispatch('care-plan-approvals-changed');
    }

    public function cancelApproval(string $approvalUuid): void
    {
        $carePlan = $this->currentCarePlan();
        if (Auth::user()->cannot('view', $carePlan)) {
            Session::flash('error', __('care-plan.approval_access_denied'));

            return;
        }

        try {
            $approval = MedicalEventsRepository::approval()
                ->carePlanApprovalForCancellation($carePlan, $approvalUuid)
                ->loadMissing('createdBy');
            if (Auth::user()->cannot('cancel', [$approval, legalEntity()])) {
                Session::flash('error', __('approval.messages.cancel_forbidden'));

                return;
            }
            app(PatientApprovalService::class)->cancel($approval);
            Session::flash('success', __('care-plan.approval_cancelled'));
            $this->fetchApprovals();
            $this->dispatch('care-plan-approvals-changed');
        } catch (EHealthJobTimeoutException) {
            Session::flash('warning', __('care-plan.approval_cancel_unconfirmed'));
        } catch (Exception $e) {
            Log::error('CarePlanApprovals: failed to cancel: ' . $e->getMessage());
            Session::flash('error', __('care-plan.approval_cancel_error'));
        }
    }

    public function render()
    {
        $visibleApprovals = collect($this->approvals)->filter(static function (array $approval): bool {
            // Recheck expiry on every render, including rows loaded before their access expired.
            $expiresAt = $approval['expires_at'] ?? null;

            return $expiresAt !== null
                ? CarbonImmutable::parse($expiresAt)->isFuture()
                : (($approval['requestPending'] ?? false) && !($approval['is_verified'] ?? false));
        });

        return view('livewire.care-plan.care-plan-approvals', compact('visibleApprovals'));
    }

    public function getSelectedEmployeeHasAccessProperty(): bool
    {
        return collect($this->approvals)->contains(function (array $approval): bool {
            $expiresAt = $approval['expires_at'] ?? null;

            return data_get($approval, 'granted_to.value') === $this->newApproval->employee_uuid
                && ($approval['is_verified'] ?? false)
                && $expiresAt !== null && CarbonImmutable::parse($expiresAt)->isFuture();
        });
    }

    protected function currentCarePlan(): CarePlan
    {
        return Repository::carePlan()->findById($this->carePlanId) ?? abort(404);
    }

    protected function guardReadOnlyApprovals(): bool
    {
        $carePlan = $this->currentCarePlan();
        if (!$this->guardApprovalAccess($carePlan)) {
            return true;
        }
        $this->isReadOnly = CarePlanStatus::fromStored($carePlan->status)->isTerminal();
        $this->statusLabel = CarePlanStatus::labelFor($carePlan->status);

        if (!$this->isReadOnly) {
            return false;
        }

        $message = __('care-plan.cannot_mutate_terminal_care_plan', [
            'status' => CarePlanStatus::labelFor($carePlan->status),
        ]);
        $this->errorMessage = $message;
        Session::flash('error', $message);

        return true;
    }

    #[Locked]
    public ?int $smsResendLinkId = null;

    #[Locked]
    public bool $isSmsResending = false;

    public function resendSms(): void
    {
        if (!$this->approvalId || $this->smsResent || $this->isSmsResending
            || AuthenticationMethod::confirmationMethod($this->currentAuthMethod ?? []) !== AuthenticationMethod::OTP) {
            return;
        }
        try {
            $plan = $this->carePlanForSms();
            if (!$this->guardApprovalAccess($plan)) {
                return;
            }
            app(MedicalRequestOwnership::class)->approvalForCarePlan($plan, $this->approvalId);
            $this->resetValidation('verificationCode');
            $this->isSmsResending = true;
            $token = session()->get(config('ehealth.api.oauth.bearer_token'));
            if (!$token || !Auth::user() || !legalEntity()) {
                throw new RuntimeException(__('care-plan.sync_session_expired'));
            }
            $result = $this->requestCarePlanApprovalSmsResend($plan, $this->approvalId, legalEntity(), Auth::user(), $token);
            if ($result['status'] === 'pending') {
                $this->smsResendLinkId = $result['linkId'];
                Session::flash('info', __('care-plan.sms_resend_processing'));

                return;
            }
            $this->finishSmsResend($result['status']);
        } catch (Throwable $exception) {
            $this->isSmsResending = false;
            Log::warning('Care plan approval SMS resend failed', ['exceptionType' => $exception::class]);
            $message = $exception instanceof EHealthValidationException ? $exception->getFormattedMessage()
                : ($exception instanceof RuntimeException ? $exception->getMessage() : __('care-plan.sms_resend_error'));
            $this->addError('verificationCode', $message);
            Session::flash('error', $message);
        }
    }

    public function checkSmsResendStatus(): void
    {
        if ($this->smsResendLinkId === null || !$this->approvalId) {
            return;
        }
        $plan = $this->carePlanForSms();
        if (Auth::user()->cannot('view', $plan)) {
            Session::flash('error', __('care-plan.approval_access_denied'));

            return;
        }
        $state = MedicalEventsRepository::approval()->carePlanSmsResendState($plan, $this->approvalId, $this->smsResendLinkId);
        if (in_array($state['status'], ['PENDING', 'PROCESSING'], true)) {
            return;
        }
        $this->finishSmsResend(strtolower($state['status']));
    }

    protected function finishSmsResend(string $status): void
    {
        $this->smsResendLinkId = null;
        $this->isSmsResending = false;
        if ($status === 'granted') {
            $this->closeAuthModal();
            Session::flash('success', __('care-plan.approval_verified'));
            $this->onSmsApprovalAlreadyGranted();
        } elseif ($status === 'processed') {
            $this->smsResent = true;
            $this->verificationCode = '';
            $this->resetValidation('verificationCode');
            Session::flash('success', __('care-plan.sms_resent'));
        } else {
            $this->smsResent = false;
            $this->addError('verificationCode', __('care-plan.sms_resend_error'));
            Session::flash('error', __('care-plan.sms_resend_error'));
        }
    }

    protected function buildCarePlanApprovalPayload(
        CarePlan $carePlan,
        string $employeeUuid,
        string $accessLevel = 'write',
        ?string $authorizeWith = null,
        ?LegalEntity $legalEntity = null,
    ): array {
        $payload = [
            'resources' => [
                [
                    'identifier' => [
                        'type' => [
                            'coding' => [['system' => 'eHealth/resources', 'code' => 'care_plan']],
                        ],
                        'value' => $carePlan->uuid,
                    ],
                ],
            ],
            'granted_to' => [
                'identifier' => [
                    'type' => [
                        'coding' => [['system' => 'eHealth/resources', 'code' => 'employee']],
                    ],
                    'value' => $employeeUuid,
                ],
            ],
            'access_level' => $accessLevel,
            'authorize_with' => $authorizeWith ?: null,
        ];

        if ($carePlan->skipsPatientOtp($legalEntity) || empty($payload['authorize_with'])) {
            unset($payload['authorize_with']);
        }

        return $payload;
    }

    protected function createCarePlanApproval(
        CarePlan $carePlan,
        string $patientUuid,
        string $employeeUuid,
        string $accessLevel = 'write',
        ?string $authorizeWith = null,
        ?LegalEntity $legalEntity = null,
        ?User $user = null,
        ?string $bearerToken = null,
    ): array {
        Repository::carePlan()->assertApprovalCreationReady($carePlan);
        $legalEntity ??= legalEntity();
        if (!$legalEntity || !$user || !$bearerToken || $patientUuid !== $carePlan->person?->uuid) {
            throw new RuntimeException(__('care-plan.approval_create_error'));
        }
        $repository = MedicalEventsRepository::approval();
        Repository::carePlan()->assertApprovalRecipient($employeeUuid, $legalEntity->id);
        $confirmWithoutOtp = $carePlan->skipsPatientOtp($legalEntity);
        $lock = Cache::lock('care-plan-approval-create:'.$carePlan->id.':'.$employeeUuid.':'.$accessLevel, 120);
        if (!$lock->get()) {
            throw new RuntimeException(__('care-plan.approval_processing'));
        }
        $link = null;
        $creationConfirmed = false;
        try {
            $existing = $repository->reusableForCarePlan($carePlan, $employeeUuid, $accessLevel);
            if ($existing !== null) {
                if ($existing->getAttribute('is_verified') && $existing->expiresAt?->isFuture() === true) {
                    return ['outcome' => CarePlanApprovalCreateOutcome::Granted, 'approvalId' => $existing->uuid, 'pollingLinkId' => null, 'authMethod' => null];
                }
                $existingLink = $existing->ehealthLinks->filter(fn ($candidate): bool => data_get($candidate->job?->request_data, 'operation') !== 'resendSms')->sortByDesc('id')->first();
                if ($existingLink !== null && !in_array($existingLink->status, ['PROCESSED', 'FAILED'], true)) {
                    return $this->carePlanApprovalResultForLink($existingLink->id);
                }
                try {
                    $data = $this->requireApprovalResponse(EHealth::approval()->withToken($bearerToken)->getApprovalDetails($patientUuid, $existing->uuid))->validate();
                } catch (EHealthResponseException $exception) {
                    if ($exception->response->status() !== 404) {
                        throw $exception;
                    }
                    $repository->markUnavailable($existing);
                    $data = []; // A missing remote resource expires local access without inventing approval fields.
                }
                if ($data !== []) {
                    $repository->sync($data, $carePlan, $existing);
                }
                $existing->refresh();
                if ($this->carePlanApprovalIsGranted($data)) {
                    return ['outcome' => CarePlanApprovalCreateOutcome::Granted, 'approvalId' => $existing->uuid, 'pollingLinkId' => null, 'authMethod' => null];
                }
                if (!$existing->getAttribute('is_verified') && $existing->expiresAt?->isFuture() === true
                    && ($confirmWithoutOtp || $authorizeWith === null || $existing->authorizeWith === $authorizeWith)) {
                    if ($confirmWithoutOtp) {
                        $this->confirmCarePlanApprovalWithoutOtp($patientUuid, $existing->uuid, $bearerToken);

                        return ['outcome' => CarePlanApprovalCreateOutcome::Granted, 'approvalId' => $existing->uuid, 'pollingLinkId' => null, 'authMethod' => null];
                    }

                    return $this->carePlanApprovalResultFromData($data, $patientUuid, $existing->authorizeWith);
                }
            }
            $payload = $this->buildCarePlanApprovalPayload($carePlan, $employeeUuid, $accessLevel, $authorizeWith, $legalEntity);
            $link = $repository->beginCarePlanRequest($carePlan, $payload, $confirmWithoutOtp);
            $response = $this->requireApprovalResponse(EHealth::approval()->withToken($bearerToken)->createApproval($patientUuid, $payload));
            $data = $response->validate();
            if ($response->getStatusCode() === 202) {
                $href = collect($data['links'] ?? [])->first(fn (array $candidate): bool => str_contains((string) ($candidate['href'] ?? ''), '/jobs/'))['href'] ?? null;
                if (!$href) {
                    throw new RuntimeException(__('care-plan.submission_unconfirmed'));
                }
                $repository->trackRequest($link, $href, $data);
                Bus::batch([new RemoteEHealthLinksProcessing($link, $legalEntity, standalone: true)])
                    ->withOption('legal_entity_id', $legalEntity->id)
                    ->withOption('token', Crypt::encryptString($bearerToken))
                    ->withOption('user', $user)
                    ->name(RemoteEHealthLinksProcessing::BATCH_NAME)->onQueue('sync')->dispatch();

                return ['outcome' => CarePlanApprovalCreateOutcome::Async, 'approvalId' => null, 'pollingLinkId' => $link->id, 'authMethod' => null];
            }
            $repository->completeCarePlanRequest($link, $data);
            $creationConfirmed = true;
            if ($confirmWithoutOtp && empty($data['is_verified'])) {
                $this->confirmCarePlanApprovalWithoutOtp($patientUuid, $data['id'], $bearerToken);

                return ['outcome' => CarePlanApprovalCreateOutcome::Granted, 'approvalId' => $data['id'], 'pollingLinkId' => null, 'authMethod' => null];
            }

            return $this->carePlanApprovalResultFromData($data, $patientUuid, $authorizeWith);
        } catch (Throwable $exception) {
            if ($link !== null && !$creationConfirmed && ($exception instanceof EHealthValidationException
                || ($exception instanceof EHealthResponseException && $exception->getCode() >= 400 && $exception->getCode() < 500 && $exception->getCode() !== 408))) {
                RemoteEHealthLinksProcessing::markSubmissionFailed($link);
            }
            throw $exception;
        } finally {
            $lock->release();
        }
    }

    protected function carePlanApprovalResultForLink(int $linkId): array
    {
        $result = $this->resolveCarePlanApprovalJob($linkId);
        if (($result['outcome']->value === 'failed')) {
            throw new RuntimeException($result['errorMessage'] ?: __('care-plan.approval_result_unavailable'));
        }
        if (($result['outcome']->value === 'granted')) {
            return ['outcome' => CarePlanApprovalCreateOutcome::Granted, 'approvalId' => $result['approvalId'], 'pollingLinkId' => null, 'authMethod' => null];
        }
        if (in_array($result['outcome']->value, ['otp_required', 'offline_required'], true)) {
            return ['outcome' => ($result['outcome']->value === 'otp_required') ? CarePlanApprovalCreateOutcome::OtpRequired : CarePlanApprovalCreateOutcome::OfflineRequired, 'approvalId' => $result['approvalId'], 'pollingLinkId' => null, 'authMethod' => $result['authMethod']];
        }

        return ['outcome' => CarePlanApprovalCreateOutcome::Async, 'approvalId' => null, 'pollingLinkId' => $linkId, 'authMethod' => null];
    }

    protected function pendingCarePlanApprovalConfirmation(string $patientUuid, string $approvalId, ?string $authorizeWith = null): array
    {
        $response = $this->requireApprovalResponse(EHealth::approval()->getApprovalDetails($patientUuid, $approvalId));
        $data = $response->validate();
        if (($data['id'] ?? null) !== $approvalId) {
            throw new RuntimeException(__('care-plan.approval_result_unavailable'));
        }
        $result = $this->carePlanApprovalResultFromData($data, $patientUuid, $authorizeWith);
        if (($result['outcome']->value === 'granted')) {
            MedicalEventsRepository::approval()->storePatientResponse($patientUuid, $approvalId, $data);
        }

        return $result;
    }

    protected function carePlanApprovalResultFromData(array $data, ?string $patientUuid = null, ?string $authorizeWith = null): array
    {
        if ($this->carePlanApprovalIsGranted($data)) {
            return ['outcome' => CarePlanApprovalCreateOutcome::Granted, 'approvalId' => $data['id'], 'pollingLinkId' => null, 'authMethod' => null];
        }
        if (($data['is_verified'] ?? null) !== false || empty($data['expires_at'])
            || !CarbonImmutable::parse($data['expires_at'])->isFuture()) {
            throw new RuntimeException(__('care-plan.approval_result_unavailable'));
        }
        $method = $this->extractCarePlanApprovalAuthMethod($data);
        $method = app(PatientApprovalService::class)->resolveConfirmationMethod($method ?? [], $patientUuid, $data['authorize_with'] ?? $authorizeWith);
        if (isset($data['id']) && AuthenticationMethod::confirmationMethod($method ?? []) !== null) {
            $outcome = AuthenticationMethod::confirmationMethod($method) === AuthenticationMethod::OTP
                ? CarePlanApprovalCreateOutcome::OtpRequired : CarePlanApprovalCreateOutcome::OfflineRequired;

            return ['outcome' => $outcome, 'approvalId' => $data['id'], 'pollingLinkId' => null, 'authMethod' => $method];
        }

        throw new RuntimeException(__('care-plan.approval_result_unavailable'));
    }

    protected function carePlanApprovalIsGranted(array $data): bool
    {
        return ($data['is_verified'] ?? false) === true
            && !empty($data['expires_at'])
            && CarbonImmutable::parse($data['expires_at'])->isFuture();
    }

    protected function verifyCarePlanApproval(string $patientUuid, string $approvalId, ?int $code, ?string $bearerToken = null): EHealthResponse
    {
        $response = EHealth::approval()->verifyConfirmed($patientUuid, $approvalId, $code, $bearerToken);
        MedicalEventsRepository::approval()->storePatientResponse($patientUuid, $approvalId, $response->validate());

        return $response;
    }

    protected function confirmCarePlanApprovalWithoutOtp(string $patientUuid, string $approvalId, ?string $bearerToken = null): EHealthResponse
    {
        return $this->verifyCarePlanApproval($patientUuid, $approvalId, null, $bearerToken);
    }

    protected function requireApprovalResponse(EHealthResponse $response): EHealthResponse
    {
        if (!$response->successful()) {
            throw new EHealthResponseException($response);
        }

        return $response;
    }

    protected function resendCarePlanApprovalSms(string $patientUuid, string $approvalId, ?string $bearerToken = null): EHealthResponse
    {
        $key = 'care-plan-otp-resend:'.$patientUuid.':'.$approvalId;

        if (!Cache::add($key, true, now()->addMinutes(10))) {
            throw new RuntimeException(__('validation.sms_already_resent'));
        }

        try {
            $api = EHealth::approval();
            if ($bearerToken !== null) {
                $api->withToken($bearerToken);
            }

            return $this->requireApprovalResponse($api->resendSms($patientUuid, $approvalId));
        } catch (Throwable $exception) {
            if ($exception instanceof EHealthValidationException
                || ($exception instanceof EHealthResponseException && $exception->getCode() >= 400 && $exception->getCode() < 500 && $exception->getCode() !== 408)) {
                Cache::forget($key);
            }
            throw $exception;
        }
    }

    protected function syncCarePlanApprovals(CarePlan $carePlan): void
    {
        // Uses Get approvals filters (granted_resource_type + granted_resources) via syncApprovals.
        MedicalEventsRepository::approval()->syncApprovals($carePlan, 'care_plan', []);
    }

    protected function requestCarePlanApprovalSmsResend(CarePlan $plan, string $approvalId, LegalEntity $legalEntity, User $user, string $bearerToken): array
    {
        $patientUuid = $plan->person->uuid;
        $api = EHealth::approval()->withToken($bearerToken);
        $approval = MedicalEventsRepository::approval()->carePlanApproval($plan, $approvalId);
        if ($approval->getAttribute('is_verified') && $approval->expiresAt?->isFuture() === true) {
            return ['status' => 'granted'];
        }
        if ($approval->expiresAt?->isFuture() !== true) {
            throw new RuntimeException(__('care-plan.approval_result_unavailable'));
        }
        if ($plan->skipsPatientOtp($legalEntity)) {
            throw new RuntimeException(__('care-plan.sms_not_required'));
        }
        try {
            $response = $this->resendCarePlanApprovalSms($patientUuid, $approvalId, $bearerToken);
        } catch (EHealthResponseException $exception) {
            if ($exception->response->status() !== 409 || EHealth::approval()->errorMessage($exception->response) !== 'Approval is already verified') {
                throw $exception;
            }
            $details = $this->requireApprovalResponse($api->getApprovalDetails($patientUuid, $approvalId));
            $confirmed = $details->validate();
            if (($confirmed['id'] ?? null) !== $approvalId || !$this->carePlanApprovalIsGranted($confirmed)) {
                throw $exception;
            }
            MedicalEventsRepository::approval()->storePatientResponse($patientUuid, $approvalId, $confirmed);

            return ['status' => 'granted'];
        }
        $link = MedicalEventsRepository::approval()->beginCarePlanSmsResend($plan, $approvalId);
        if ($response->getStatusCode() === 202) {
            $jobData = $response->validate();
            $href = collect($jobData['links'] ?? [])->first(fn (array $candidate): bool => str_contains((string) ($candidate['href'] ?? ''), '/jobs/'))['href'] ?? null;
            if (!$href) {
                MedicalEventsRepository::approval()->finishCarePlanSmsResend($link, ['status' => 'failed']);
                throw new RuntimeException(__('care-plan.submission_unconfirmed'));
            }
            MedicalEventsRepository::approval()->trackRequest($link, $href, $jobData);
            try {
                Bus::batch([new RemoteEHealthLinksProcessing($link, $legalEntity, standalone: true)])
                    ->withOption('legal_entity_id', $legalEntity->id)
                    ->withOption('token', Crypt::encryptString($bearerToken))
                    ->withOption('user', $user)
                    ->name(RemoteEHealthLinksProcessing::BATCH_NAME)->onQueue('sync')->dispatch();
            } catch (Throwable $exception) {
                MedicalEventsRepository::approval()->finishCarePlanSmsResend($link, ['status' => 'failed']);
                throw $exception;
            }

            return ['status' => 'pending', 'linkId' => $link->id];
        }
        MedicalEventsRepository::approval()->finishCarePlanSmsResend($link, ['status' => 'processed', 'response_data' => $response->validate()]);

        return ['status' => 'processed'];
    }

    protected function resolveCarePlanApprovalJob(int $pollingLinkId): array
    {
        $link = MedicalEventsRepository::approval()->requestLink($pollingLinkId);
        if (!$link || !$link->job || !$link->linkable instanceof PatientApproval) {
            return ['outcome' => CarePlanApprovalJobOutcome::Failed, 'approvalId' => null, 'authMethod' => null, 'errorMessage' => __('care-plan.approval_result_unavailable')];
        }
        if (strtoupper((string) $link->job->status) === 'FAILED') {
            return ['outcome' => CarePlanApprovalJobOutcome::Failed, 'approvalId' => null, 'authMethod' => null, 'errorMessage' => $this->formatCarePlanApprovalJobError($link->job->response_data ?? [])];
        }
        $approval = $link->linkable;
        if ($approval->getAttribute('is_verified') && $approval->expiresAt?->isFuture() === true) {
            return ['outcome' => CarePlanApprovalJobOutcome::Granted, 'approvalId' => $approval->uuid, 'authMethod' => null, 'errorMessage' => null];
        }
        if ($approval->expiresAt !== null && !$approval->expiresAt->isFuture()) {
            return ['outcome' => CarePlanApprovalJobOutcome::Failed, 'approvalId' => null, 'authMethod' => null, 'errorMessage' => __('care-plan.approval_result_unavailable')];
        }
        if (!empty($link->job->response_data['confirmationError'])) {
            return ['outcome' => CarePlanApprovalJobOutcome::Failed, 'approvalId' => null, 'authMethod' => null, 'errorMessage' => __('care-plan.approval_verify_error')];
        }
        if (strtoupper((string) $link->job->status) !== 'PROCESSED') {
            return ['outcome' => CarePlanApprovalJobOutcome::Pending, 'approvalId' => null, 'authMethod' => null, 'errorMessage' => null];
        }
        try {
            $data = EHealth::approval()->validateResourceData($link->job->response_data ?? []);
            if (($data['authentication_method_current'] ?? null) === null && $approval->approvable instanceof CarePlan
                && $approval->approvable->skipsPatientOtp()) {
                return ['outcome' => CarePlanApprovalJobOutcome::Pending, 'approvalId' => null, 'authMethod' => null, 'errorMessage' => null];
            }
            $authorizeWith = $approval->authorizeWith ?: data_get($link->job?->request_data, 'authorize_with');
            $result = $this->carePlanApprovalResultFromData($data, $approval->approvable?->person?->uuid, $authorizeWith);
        } catch (ValidationException) {
            return ['outcome' => CarePlanApprovalJobOutcome::Failed, 'approvalId' => null, 'authMethod' => null, 'errorMessage' => __('care-plan.approval_result_unavailable')];
        } catch (RuntimeException $exception) {
            return ['outcome' => CarePlanApprovalJobOutcome::Failed, 'approvalId' => null, 'authMethod' => null, 'errorMessage' => $exception->getMessage()];
        }

        return ['outcome' => ($result['outcome']->value === 'granted') ? CarePlanApprovalJobOutcome::Granted
                : (($result['outcome']->value === 'otp_required') ? CarePlanApprovalJobOutcome::OtpRequired : CarePlanApprovalJobOutcome::OfflineRequired), 'approvalId' => $result['approvalId'], 'authMethod' => $result['authMethod'], 'errorMessage' => null];
    }

    protected function extractCarePlanApprovalAuthMethod(array $data): ?array
    {
        $method = $data['response_data']['authentication_method_current']
            ?? ($data['data']['authentication_method_current']
                ?? ($data['authentication_method_current']
                    ?? ($data['urgent']['authentication_method_current'] ?? null)));

        return is_array($method) ? $method : null;
    }

    protected function formatCarePlanApprovalJobError(array $jobResult): string
    {
        if (isset($jobResult['error']['invalid']) && is_array($jobResult['error']['invalid'])) {
            $errors = [];

            foreach ($jobResult['error']['invalid'] as $invalid) {
                $entry = $invalid['entry'] ?? '';
                $rules = $invalid['rules'] ?? [];

                foreach ($rules as $rule) {
                    $errors[] = ($entry ? $entry.': ' : '').($rule['description'] ?? '');
                }
            }

            if ($errors !== []) {
                return 'Помилка від ЕСОЗ: '.implode(', ', $errors);
            }
        }

        if (isset($jobResult['error']['message'])) {
            return 'Помилка від ЕСОЗ: '.$jobResult['error']['message'];
        }

        return __('care-plan.approval_create_error');
    }

    protected function approvalEmployees(int $legalEntityId): array
    {
        return Repository::carePlan()->approvalEmployees($legalEntityId)
            ->map(fn ($employee): array => ['uuid' => $employee->uuid, 'label' => trim($employee->fullName).' ('.$employee->positionLabel().')'])->all();
    }
}
