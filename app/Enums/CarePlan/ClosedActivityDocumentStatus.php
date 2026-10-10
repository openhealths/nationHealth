<?php

declare(strict_types=1);

namespace App\Enums\CarePlan;

/** Closed documents do not prevent cancel/complete of their parent activity. */
enum ClosedActivityDocumentStatus: string
{
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case Rejected = 'rejected';
    case Expired = 'expired';
    case EnteredInError = 'entered-in-error';
    case Recalled = 'recalled';
    case Revoked = 'revoked';
    case Stopped = 'stopped';

    public static function fromStored(string $status): ?self
    {
        $status = strtolower(str_replace('_', '-', trim($status)));

        return self::tryFrom($status === 'canceled' ? 'cancelled' : $status);
    }
}
