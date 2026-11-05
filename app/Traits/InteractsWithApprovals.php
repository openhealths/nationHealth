<?php

declare(strict_types=1);

namespace App\Traits;

use App\Enums\Person\AuthenticationMethod;
use App\Models\CarePlan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

/**
 * Trait InteractsWithApprovals
 *
 * Provides shared variables and logic for EHealth Approvals with SMS (OTP) verification.
 */
trait InteractsWithApprovals
{
    /**
     * Determine if the authentication modal should be visible.
     */
    public bool $showAuthModal = false;

    /**
     * Verification code entered by the user.
     */
    public string $verificationCode = '';

    public bool $offlineAcknowledged = false;

    /**
     * Phone number shown in the OTP authentication modal (read-only display).
     */
    #[Locked]
    public ?string $phoneNumber = null;

    /**
     * Indicates whether the SMS has already been resent.
     *
     * Entangled with Alpine as `sentOnce`, so it cannot be locked and the browser can reset it.
     * Enforcing the one-resend rule needs server-side state instead.
     */
    public bool $smsResent = false;

    /**
     * Approval being verified. Locked: the browser must not be able to point the OTP flow at
     * another patient's approval.
     */
    #[Locked]
    public ?string $approvalId = null;

    /** Bound as an input by the standalone request forms, so it stays writable. */
    public ?string $patientId = null;

    /** Whether we are waiting for an async eHealth approval job to complete. */
    #[Locked]
    public bool $isPolling = false;

    /** EhealthLink id being polled (null when not polling). */
    #[Locked]
    public ?int $pollingLinkId = null;

    /**
     * authentication_method_current from eHealth for the pending approval OTP modal.
     *
     * @var array<string, mixed>|null
     */
    #[Locked]
    public ?array $currentAuthMethod = null;

    /**
     * Validation rules for the verification code.
     */
    protected function approvalVerificationRules(): array
    {
        if (AuthenticationMethod::confirmationMethod($this->currentAuthMethod ?? []) === null) {
            throw ValidationException::withMessages(['verificationCode' => __('care-plan.approval_result_unavailable')]);
        }

        if ($this->isOfflineAuthMethod()) {
            return [
                'verificationCode' => ['nullable'],
                'offlineAcknowledged' => ['accepted'],
            ];
        }

        return [
            'verificationCode' => ['required', 'string', 'size:4'],
        ];
    }

    protected function approvalVerificationMessages(): array
    {
        return ['offlineAcknowledged.accepted' => __('care-plan.approval_offline_acknowledgement_required')];
    }

    /**
     * Check if the current authentication method is OFFLINE or document-based (no SMS required).
     */
    public function isOfflineAuthMethod(?array $authMethod = null): bool
    {
        $method = $authMethod ?? $this->currentAuthMethod;

        return AuthenticationMethod::confirmationMethod($method ?? []) === AuthenticationMethod::OFFLINE;
    }

    /**
     * Open the authentication modal.
     */
    public function openAuthModal(): void
    {
        if (AuthenticationMethod::confirmationMethod($this->currentAuthMethod ?? []) === null) {
            $this->showAuthModal = false;
            Session::flash('error', __('care-plan.approval_result_unavailable'));

            return;
        }

        $this->phoneNumber = AuthenticationMethod::confirmationPhone($this->currentAuthMethod);
        $this->showAuthModal = true;
        $this->verificationCode = '';
        $this->offlineAcknowledged = false;
        $this->resetValidation(['verificationCode', 'offlineAcknowledged']);
        $this->smsResent = false;
    }

    /**
     * Close the authentication modal.
     */
    public function closeAuthModal(): void
    {
        $this->showAuthModal = false;
        $this->verificationCode = '';
        $this->offlineAcknowledged = false;
    }

    /**
     * Reset the SMS state.
     */
    public function resetSmsState(): void
    {
        $this->verificationCode = '';
        $this->smsResent = false;
    }

    protected function selectedApprovalAuthMethod(string $methodUuid): array
    {
        $method = collect($this->authMethods)->first(fn (array $method): bool => ($method['id'] ?? $method['uuid'] ?? null) === $methodUuid);
        if ($method === null || !in_array($method['type'] ?? null, ['OTP', 'OFFLINE', 'THIRD_PERSON'], true)) {
            throw ValidationException::withMessages(['selectedAuthMethodUuid' => __('care-plan.approval_auth_method_invalid')]);
        }

        return $method;
    }

    protected function guardApprovalAccess(CarePlan $carePlan): bool
    {
        if (Auth::user()->cannot('manage', $carePlan)) {
            Session::flash('error', __('care-plan.approval_access_denied'));

            return false;
        }

        if ($carePlan->requiresFhirImport()) {
            Session::flash('error', __('care-plan.full_sync_required'));

            return false;
        }

        return true;
    }
}
