<?php

declare(strict_types=1);

namespace App\Services\MedicalEvents;

final class CarePlanApprovalJobStatusResult
{
    public function __construct(
        public readonly CarePlanApprovalJobOutcome $outcome,
        public readonly ?string $approvalId = null,
        public readonly ?array $authMethod = null,
        public readonly ?string $errorMessage = null,
    ) {
    }

    public function isPending(): bool
    {
        return $this->outcome === CarePlanApprovalJobOutcome::Pending;
    }

    public function requiresOtp(): bool
    {
        return $this->outcome === CarePlanApprovalJobOutcome::OtpRequired;
    }

    public function isGranted(): bool
    {
        return $this->outcome === CarePlanApprovalJobOutcome::Granted;
    }

    public function isFailed(): bool
    {
        return $this->outcome === CarePlanApprovalJobOutcome::Failed;
    }
}
