<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\MedicalEvents\CarePlan;

use App\Enums\CarePlan\ActivityStatus;
use App\Enums\CarePlanStatus;
use App\Models\CarePlan;
use App\Models\CarePlanActivity;
use App\Repositories\CarePlanActivityRepository;

trait ValidatesCarePlanStatusChanges
{
    protected function activityStatusChangeBlockReason(CarePlanActivity $activity, string $action): ?string
    {
        $open = app(CarePlanActivityRepository::class)->findOpenDocuments($activity);
        if ($open === []) {
            return null;
        }

        $reasons = collect($open)
            ->map(fn (array $doc): string => $this->describeOpenDocument($doc))
            ->unique()
            ->values()
            ->all();

        $key = $action === 'complete_activity'
            ? 'care-plan.cannot_complete_activity_open_docs'
            : 'care-plan.cannot_cancel_activity_open_docs';

        return __($key, ['reasons' => implode('; ', $reasons)]);
    }

    protected function planCancelBlockReason(CarePlan $carePlan): ?string
    {
        $status = CarePlanStatus::fromStored($carePlan->status);
        if ($status->isTerminal()) {
            return __('care-plan.cannot_mutate_terminal_care_plan', [
                'status' => $status->label(),
            ]);
        }

        $carePlan->loadMissing('activities');
        $blocking = $carePlan->activities->filter(static fn (CarePlanActivity $activity): bool => ActivityStatus::fromStored((string) $activity->status)?->blocksPlanCancellation() === true);
        if ($blocking->isEmpty()) {
            return null;
        }

        $statuses = $blocking
            ->map(fn (CarePlanActivity $activity): string => strtolower((string) $activity->status))
            ->unique()
            ->map(fn (string $status): string => __('care-plan.status.'.$this->normalizeStatusKey($status)))
            ->values()
            ->all();

        return __('care-plan.cannot_cancel_plan_blocking_activities', [
            'statuses' => implode(', ', $statuses),
        ]);
    }

    protected function planCompleteBlockReason(CarePlan $carePlan): ?string
    {
        $status = CarePlanStatus::fromStored($carePlan->status);
        if ($status->isTerminal()) {
            return __('care-plan.cannot_mutate_terminal_care_plan', [
                'status' => $status->label(),
            ]);
        }

        $carePlan->loadMissing('activities');

        $open = $carePlan->activities
            ->filter(fn (CarePlanActivity $activity): bool => ActivityStatus::fromStored((string) $activity->status)?->isFinalForPlanCompletion() !== true)
            ->values();

        if ($open->isNotEmpty()) {
            $statuses = $open
                ->map(fn (CarePlanActivity $activity): string => strtolower((string) $activity->status))
                ->unique()
                ->map(fn (string $activityStatus): string => __('care-plan.status.'.$this->normalizeStatusKey($activityStatus)))
                ->values()
                ->all();

            return __('care-plan.cannot_complete_plan_open_activities', [
                'statuses' => implode(', ', $statuses),
            ]);
        }

        $hasCompleted = $carePlan->activities->contains(
            fn (CarePlanActivity $activity): bool => ActivityStatus::fromStored((string) $activity->status) === ActivityStatus::Completed
        );

        if (!$hasCompleted) {
            return __('care-plan.cannot_complete_plan_no_completed_activity');
        }

        return null;
    }

    private function describeOpenDocument(array $doc): string
    {
        $typeLabel = match ($doc['type']) {
            'medication_request_request' => __('care-plan.open_doc_type.medication_request_request'),
            'medication_request' => __('care-plan.open_doc_type.medication_request'),
            'service_request' => __('care-plan.open_doc_type.service_request'),
            'device_request' => __('care-plan.open_doc_type.device_request'),
            default => $doc['type'],
        };

        $statusLabel = __('care-plan.status.'.$this->normalizeStatusKey($doc['status']));

        return $typeLabel.' ('.$statusLabel.')';
    }

    private function normalizeStatusKey(string $status): string
    {
        return str_replace('-', '_', strtolower($status));
    }
}
