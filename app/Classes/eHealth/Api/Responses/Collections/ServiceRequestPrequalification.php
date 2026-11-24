<?php

declare(strict_types=1);

namespace App\Classes\eHealth\Api\Responses\Collections;

use Illuminate\Support\Collection;

/** @extends Collection<int, array{program_id: string, program_name: string, status: string, rejection_reason?: string}> */
class ServiceRequestPrequalification extends Collection
{
    public function rejectionReason(): ?string
    {
        $rejected = $this->firstWhere('status', 'INVALID');
        if ($rejected === null) {
            return null;
        }

        $reason = trim($rejected['rejection_reason'] ?? '');

        return $reason !== '' ? $reason : __('care-plan.referral_prequalify_failed');
    }
}
