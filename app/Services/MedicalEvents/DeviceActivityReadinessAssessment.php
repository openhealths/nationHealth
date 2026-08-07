<?php

declare(strict_types=1);

namespace App\Services\MedicalEvents;

final class DeviceActivityReadinessAssessment
{
    /**
     * @param  list<string>  $blockingIssues
     * @param  list<string>  $warnings
     */
    public function __construct(
        public readonly array $blockingIssues,
        public readonly array $warnings,
    ) {
    }

    public function blockingMessage(): ?string
    {
        if ($this->blockingIssues === []) {
            return null;
        }

        return implode(' ', $this->blockingIssues);
    }
}
