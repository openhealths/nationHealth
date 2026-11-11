<?php

declare(strict_types=1);

namespace App\Livewire\CarePlan;

use App\Classes\eHealth\EHealth;
use App\Enums\CarePlanStatus;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Exceptions\EHealth\EHealthValidationException;
use App\Exceptions\EHealth\EHealthJobTimeoutException;
use App\Models\CarePlan;
use App\Models\Employee\Employee;
use App\Models\LegalEntity;
use App\Repositories\Repository;
use App\Repositories\MedicalEvents\Repository as MedicalEventsRepository;
use App\Services\MedicalEvents\CarePlanApprovalService;
use App\Services\MedicalEvents\PatientApprovalService;
use App\Services\MedicalEvents\MedicalRequestOwnership;
use App\Traits\FormTrait;
use App\Traits\InteractsWithApprovals;
use Exception;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;
use Throwable;
use App\Livewire\CarePlan\Concerns\HandlesCarePlanApprovalSms;

class CarePlanApprovals extends Component
{
    use FormTrait;
    use InteractsWithApprovals;
    use HandlesCarePlanApprovalSms;

    #[Locked]
    public int $carePlanId;

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
    public array $newApproval = [
        'employee_uuid' => '',  // UUID of the Employee (doctor) in eHealth to grant access to
    ];

    #[Locked]
    public bool $isReadOnly = false;

    #[Locked]
    public string $statusLabel = '';

    /** INPATIENT plans at this legal entity skip patient SMS. */
    #[Locked]
    public bool $skipsPatientOtp = false;

    public function mount(LegalEntity $legalEntity, CarePlan $carePlan): void
    {
        $this->authorize('view', $carePlan);

        $this->carePlanId = $carePlan->id;
        $this->carePlanUuid = $carePlan->uuid ?? '';
        $this->patientUuid = $carePlan->person?->uuid ?? '';
        $this->isReadOnly = CarePlanStatus::fromStored($carePlan->status)->isTerminal();
        $this->statusLabel = CarePlanStatus::labelFor($carePlan->status);
        $this->skipsPatientOtp = app(CarePlanApprovalService::class)->skipsPatientOtp($carePlan);
        $this->fetchApprovals();

        // Load active employees for the dropdown, filtered by the current active legal entity.
        // We must use the active legal entity (not the care plan's owner), because eHealth validates
        // that the granted employee belongs to the requesting clinic.
        $legalEntityId = legalEntity()?->id ?? $legalEntity->id;
        if ($legalEntityId) {
            $this->employees = Repository::carePlan()->approvalEmployees($legalEntityId);

            $writerUuid = Auth::user()?->getCarePlanWriterEmployee($carePlan->termsOfServiceCode())?->uuid;
            if ($writerUuid && collect($this->employees)->contains(fn (array $employee): bool => $employee['uuid'] === $writerUuid)) {
                $this->newApproval['employee_uuid'] = $writerUuid;
            } elseif (count($this->employees) === 1) {
                $this->newApproval['employee_uuid'] = $this->employees[0]['uuid'];
            }
        }

    }

    public function loadAuthMethods(): void
    {
        if (!$this->guardApprovalAccess($this->currentCarePlan()) || $this->isReadOnly) {
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
            $this->skipsPatientOtp = app(CarePlanApprovalService::class)->skipsPatientOtp($carePlan);
            $this->approvals = MedicalEventsRepository::approval()->localForCarePlan($carePlan);
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
     * Submit a new approval request to eHealth via CarePlanApprovalService.
     */
    public function createApproval(): void
    {
        $this->errorMessage = null;

        $this->validate([
            'newApproval.employee_uuid' => 'required|uuid',
        ]);

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

            $service = app(CarePlanApprovalService::class);

            $result = $service->create(
                carePlan: $carePlan,
                patientUuid: $this->patientUuid,
                employeeUuid: $this->newApproval['employee_uuid'],
                accessLevel: $service->resolveAccessLevel($carePlan),
                authorizeWith: $this->skipsPatientOtp ? null : ($this->selectedAuthMethodUuid ?: null),
                user: Auth::user(),
                bearerToken: Session::get(config('ehealth.api.oauth.bearer_token')),
            );

            if ($result->isAsync()) {
                $this->pollingLinkId = $result->pollingLinkId;
                $this->approvalId = $result->approvalId;
                $this->isPolling = true;
                Session::flash('info', __('care-plan.approval_processing'));

                return;
            }

            if ($result->requiresConfirmation()) {
                $this->approvalId = $result->approvalId;
                $this->currentAuthMethod = $result->authMethod ?? $this->currentAuthMethod;
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

        $status = app(CarePlanApprovalService::class)->resolveAsyncJob($this->pollingLinkId);

        if ($status->isPending()) {
            return;
        }

        $this->isPolling = false;
        $this->pollingLinkId = null;

        if ($status->isFailed()) {
            $this->errorMessage = $status->errorMessage ?: __('care-plan.approval_create_error');
            Session::flash('error', $this->errorMessage);

            return;
        }

        if ($status->approvalId) {
            $this->approvalId = $status->approvalId;
        }

        if ($status->requiresConfirmation()) {
            $this->currentAuthMethod = $status->authMethod ?? $this->currentAuthMethod;
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
            $result = app(CarePlanApprovalService::class)->pendingConfirmation($this->patientUuid, $approvalUuid, $approval->authorizeWith);
            $this->approvalId = $result->approvalId;
            if ($result->isGranted()) {
                $this->fetchApprovals();
                Session::flash('success', __('care-plan.approval_verified'));

                return;
            }
            $this->currentAuthMethod = $result->authMethod;
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

            $service = app(CarePlanApprovalService::class);
            $result = $service->create(
                carePlan: $carePlan,
                patientUuid: $this->patientUuid,
                employeeUuid: $employeeUuid,
                accessLevel: $service->resolveAccessLevel($carePlan),
                authorizeWith: $this->skipsPatientOtp ? null : ($this->selectedAuthMethodUuid ?: null),
                user: Auth::user(),
                bearerToken: Session::get(config('ehealth.api.oauth.bearer_token')),
            );

            if ($result->isAsync()) {
                $this->pollingLinkId = $result->pollingLinkId;
                $this->approvalId = $result->approvalId;
                $this->isPolling = true;
                Session::flash('info', __('care-plan.approval_processing'));

                return;
            }

            if ($result->requiresConfirmation()) {
                $this->approvalId = $result->approvalId;
                $this->currentAuthMethod = $result->authMethod ?? $this->currentAuthMethod;
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
            $response = app(CarePlanApprovalService::class)->confirmWithoutOtp($this->patientUuid, $approvalUuid);
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
            app(CarePlanApprovalService::class)->verify(
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
        return view('livewire.care-plan.care-plan-approvals');
    }

    public function getSelectedEmployeeHasAccessProperty(): bool
    {
        return collect($this->approvals)->contains(function (array $approval): bool {
            $expiresAt = $approval['expiresAt'] ?? ($approval['expires_at'] ?? null);

            return data_get($approval, 'grantedTo.value', data_get($approval, 'granted_to.value')) === $this->newApproval['employee_uuid']
                && ($approval['isVerified'] ?? ($approval['is_verified'] ?? false))
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
}
