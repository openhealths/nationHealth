<?php

declare(strict_types=1);

namespace App\Services\MedicalEvents;

use App\Models\CarePlanActivity;
use App\Repositories\MedicalEvents\MedicalEventsRequestStatuses;

/**
 * Serialises remaining-quantity checks so two Livewire tabs cannot both issue
 * against the same activity after seeing a stale leftover snapshot.
 */
final class ActivityRemainingQuantityGuard
{
    public function assertCanIssue(int $activityId, float $qty, callable $issuedSum): CarePlanActivity
    {
        return app(\App\Repositories\CarePlanActivityRepository::class)->assertCanIssue($activityId, $qty, $issuedSum);
    }

    /**
     * Statuses that still occupy remaining quantity (drafts included).
     *
     * @return list<string>
     */
    public static function occupyingStatusesExcluded(): array
    {
        return array_values(array_filter(
            MedicalEventsRequestStatuses::EXCLUDED_FROM_ISSUED_SUM,
            static fn (string $status): bool => !in_array(strtolower($status), ['draft', 'new'], true)
        ));
    }
}
