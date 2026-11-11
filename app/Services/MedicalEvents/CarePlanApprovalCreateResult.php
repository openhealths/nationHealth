<?php

declare(strict_types=1);

namespace App\Services\MedicalEvents;

class CarePlanApprovalCreateResult
{
    public function __construct(
        public readonly CarePlanApprovalCreateOutcome $outcome,
        public readonly ?string $approvalId = null,
        public readonly ?int $pollingLinkId = null,
        public readonly ?array $authMethod = null,
    ) {
    }

    public function isAsync(): bool
    {
        return $this->outcome === CarePlanApprovalCreateOutcome::Async;
    }

    public function requiresOtp(): bool
    {
        return $this->outcome === CarePlanApprovalCreateOutcome::OtpRequired;
    }

    public function requiresConfirmation(): bool
    {
        return in_array($this->outcome, [CarePlanApprovalCreateOutcome::OtpRequired, CarePlanApprovalCreateOutcome::OfflineRequired], true);
    }

    public function isGranted(): bool
    {
        return $this->outcome === CarePlanApprovalCreateOutcome::Granted;
    }
}
