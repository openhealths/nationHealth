<?php

declare(strict_types=1);

namespace App\Enums\CarePlan;

enum ActivityStatus: string
{
    case Draft = 'draft';
    case New = 'new';
    case Scheduled = 'scheduled';
    case InProgress = 'in-progress';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case EnteredInError = 'entered-in-error';

    public static function fromStored(string $status): ?self
    {
        $status = strtolower(str_replace('_', '-', trim($status)));

        return self::tryFrom($status === 'canceled' ? 'cancelled' : $status);
    }

    public function blocksPlanCancellation(): bool
    {
        return match ($this) {
            self::Scheduled, self::InProgress, self::Completed => true,
            default => false,
        };
    }

    public function isFinalForPlanCompletion(): bool
    {
        return match ($this) {
            self::Completed, self::Cancelled, self::EnteredInError => true,
            default => false,
        };
    }
}
