<?php

declare(strict_types=1);

namespace App\Traits;

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
        if ($this->isOfflineAuthMethod()) {
            return [
                'verificationCode' => ['nullable'],
            ];
        }

        return [
            'verificationCode' => ['required', 'string', 'size:4'],
        ];
    }

    /**
     * Check if the current authentication method is OFFLINE or document-based (no SMS required).
     */
    public function isOfflineAuthMethod(?array $authMethod = null): bool
    {
        $method = $authMethod ?? $this->currentAuthMethod;
        $type = $method['type'] ?? null;

        return $type === 'OFFLINE' || $type === 'THIRD_PERSON' || ($type !== 'OTP' && !is_null($type));
    }

    /**
     * Open the authentication modal.
     */
    public function openAuthModal(): void
    {
        if (is_array($this->currentAuthMethod ?? null)) {
            $this->phoneNumber = $this->currentAuthMethod['phone_number']
                ?? $this->currentAuthMethod['phoneNumber']
                ?? $this->phoneNumber;
        }

        $this->showAuthModal = true;
        $this->verificationCode = '';
        $this->smsResent = false;
    }

    /**
     * Close the authentication modal.
     */
    public function closeAuthModal(): void
    {
        $this->showAuthModal = false;
        $this->verificationCode = '';
    }

    /**
     * Reset the SMS state.
     */
    public function resetSmsState(): void
    {
        $this->verificationCode = '';
        $this->smsResent = false;
    }
}
