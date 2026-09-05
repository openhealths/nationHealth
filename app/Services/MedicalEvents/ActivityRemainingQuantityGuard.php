<?php

declare(strict_types=1);

namespace App\Services\MedicalEvents;

use App\Models\CarePlanActivity;
use App\Repositories\MedicalEvents\MedicalEventsRequestStatuses;
use Illuminate\Support\Facades\DB;

/**
 * Serialises remaining-quantity checks so two Livewire tabs cannot both issue
 * against the same activity after seeing a stale leftover snapshot.
 */
final class ActivityRemainingQuantityGuard
{
    /**
     * @param  callable(int): float  $issuedSum
     */
    public function assertCanIssue(int $activityId, float $qty, callable $issuedSum): CarePlanActivity
    {
        return DB::transaction(function () use ($activityId, $qty, $issuedSum): CarePlanActivity {
            $activity = CarePlanActivity::query()
                ->whereKey($activityId)
                ->lockForUpdate()
                ->firstOrFail();

            $cap = $activity->quantity;
            if ($cap === null || $cap === '') {
                return $activity;
            }

            $issued = max(0.0, (float) $issuedSum($activity->id));
            $remaining = max(0.0, (float) $cap - $issued);

            if ($qty > $remaining + 0.0001) {
                throw new \InvalidArgumentException(__('care-plan.activity_issue_exceeds_remaining', [
                    'remaining' => $remaining,
                ]));
            }

            return $activity;
        });
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
