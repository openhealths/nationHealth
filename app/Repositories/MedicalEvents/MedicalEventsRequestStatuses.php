<?php

declare(strict_types=1);

namespace App\Repositories\MedicalEvents;

final class MedicalEventsRequestStatuses
{
    /**
     * Statuses excluded when calculating remaining quantity for an activity.
     *
     * @var list<string>
     */
    public const EXCLUDED_FROM_ISSUED_SUM = [
        'draft',
        'new',
        'cancelled',
        'rejected',
        'declined',
        'entered-in-error',
        'expired',
        'DRAFT',
        'NEW',
        'CANCELLED',
        'REJECTED',
        'DECLINED',
        'ENTERED-IN-ERROR',
        'EXPIRED',
    ];
}
