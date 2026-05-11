<?php

declare(strict_types=1);

namespace App\Traits;

use Illuminate\Support\Facades\Session;

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
     * Indicates whether the SMS has already been resent.
     */
    public bool $smsResent = false;

    /**
     * Optional properties for specific flows that need to track approval details.
     */
    public ?string $approvalId = null;
    public ?string $patientId = null;

    /**
     * Validation rules for the verification code.
     */
    protected function approvalVerificationRules(): array
    {
        return [
            'verificationCode' => ['required', 'string', 'size:4'],
        ];
    }

    /**
     * Open the authentication modal.
     */
    public function openAuthModal(): void
    {
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
