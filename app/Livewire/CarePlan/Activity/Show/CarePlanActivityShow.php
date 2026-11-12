<?php

declare(strict_types=1);

namespace App\Livewire\CarePlan\Activity\Show;

use App\Enums\JobStatus;
use App\Livewire\CarePlan\CarePlanComponent;
use App\Models\CarePlan;
use App\Models\CarePlanActivity;
use App\Repositories\Repository;
use Exception;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;
use Livewire\Attributes\Locked;

class CarePlanActivityShow extends CarePlanComponent
{

    #[Locked]
    public int $activityId = 0;

    protected CarePlanActivity $activity;

    public string $activityProductLabel = '';

    #[Locked]
    public array $submissionStates = [];

    public function mount(CarePlan $carePlan, CarePlanActivity $activity): void
    {
        $this->bootCarePlan($carePlan);

        $this->activity = $this->ownedActivity($activity->id);
        $this->activityId = $this->activity->id;
        $this->refreshSubmissionStatus();
    }

    public function hydrate(): void
    {
        parent::hydrate();
        $this->refreshSubmissionStatus();
    }

    public function checkSubmissionStatus(): void
    {
        $this->refreshCarePlan();
    }

    protected function refreshSubmissionStatus(): void
    {
        $previousStates = $this->submissionStates;
        $this->submissionStates = Repository::carePlanActivity()->submissionStates($this->activity);
        // Read the activity after its receipt: a committed PROCESSED receipt includes the status update.
        $this->activity = $this->ownedActivity($this->activity->id);
        $this->activityProductLabel = $this->resolveActivityProductLabel($this->activity);
        $this->scopeDocumentsToActivity($this->activity->uuid);
        $pendingRedirect = Session::get('info') === __('care-plan.submission_pending');
        $latestSubmissionId = collect($this->submissionStates)->max('id');

        foreach ($this->submissionStates as $uuid => $state) {
            if ($uuid !== $this->activity->uuid && $this->activity->resolvedKind() !== 'service_request') {
                continue;
            }
            $previous = $previousStates[$uuid] ?? [];
            $wasPending = ($previous['id'] ?? null) === $state['id']
                && in_array($previous['status'] ?? null, [JobStatus::PENDING->value, JobStatus::PROCESSING->value], true);
            if (!$wasPending && !($previous === [] && $pendingRedirect && $state['id'] === $latestSubmissionId)) {
                continue;
            }

            if ($pendingRedirect && in_array($state['status'], [JobStatus::FAILED->value, JobStatus::PROCESSED->value], true)) {
                Session::forget('info');
            }
            if ($state['status'] === JobStatus::FAILED->value) {
                Session::flash('error', $state['error'] ?: __('care-plan.submission_failed_edit_draft'));
            } elseif ($state['status'] === JobStatus::PROCESSED->value) {
                $message = $uuid === $this->activity->uuid
                    ? __($this->activity->status === 'scheduled' ? 'care-plan.activity_signed' : 'care-plan.submission_confirmed')
                    : __('care-plan.referral_submission_confirmed');
                if (filled($state['number'])) {
                    $message .= ' '.__('care-plan.document_number', ['number' => $state['number']]);
                }
                Session::flash('success', $message);
            }
        }
    }

    protected function refreshCarePlan(): void
    {
        parent::refreshCarePlan();
        $this->refreshSubmissionStatus();
    }

    protected function renderCarePlan()
    {
        return view('livewire.care-plan.activity.show.care-plan-activity-show', [
            'carePlan' => $this->carePlan,
            'activity' => $this->activity,
            'activityReasonReferenceLabels' => $this->resolveActivityReasonReferenceLabels(),
        ]);
    }

    protected function resolveActivityReasonReferenceLabels(): array
    {
        $grounds = [
            'condition' => collect($this->availableConditions)->keyBy('uuid'),
            'diagnosticreport' => collect($this->availableReports)->keyBy('uuid'),
            'observation' => collect($this->availableObservations)->keyBy('uuid'),
        ];

        return collect($this->activity->reasonReference ?? [])->map(function (string $reference) use ($grounds): string {
            [$type, $uuid] = array_pad(explode('/', $reference, 2), 2, '');

            return $grounds[str_replace('_', '', strtolower($type))][$uuid]['name'] ?? $reference;
        })->all();
    }

    protected function getDeviceSignReadinessWarning(CarePlanActivity $activity): ?string
    {
        // Validation delegated to eHealth API during signing to avoid overengineering guard checks.
        return null;
    }

    protected function resolveActivityProductLabel(CarePlanActivity $activity): string
    {
        $kindLower = strtolower($activity->resolvedKind());

        if (str_contains($kindLower, 'device') && !empty($activity->productReference)) {
            try {
                $reference = (string) $activity->productReference;
                $device = dictionary()->deviceDefinitions()->first(
                    fn (array $item): bool => (string) ($item['id'] ?? ($item['uuid'] ?? '')) === $reference
                );

                if (is_array($device)) {
                    return (string) ($device['device_names'][0]['name']
                        ?? ($device['name']
                            ?? ($device['model_number'] ?? $reference)));
                }
            } catch (Exception $exception) {
                Log::warning('CarePlanActivityShow: failed to resolve device label: ' . $exception->getMessage());
            }

            return (string) $activity->productReference;
        }

        if (str_contains($kindLower, 'medication') && !empty($activity->productReference)) {
            return (string) $activity->productReference;
        }

        if (str_contains($kindLower, 'service') && !empty($activity->productReference)) {
            return $this->resolveReferralServiceLabel($activity);
        }

        if (!empty($activity->productCodeableConcept)) {
            return $this->dictionaries['device_definition_classification_type'][$activity->productCodeableConcept]
                ?? (string) $activity->productCodeableConcept;
        }

        return '';
    }
    public function updatedSelectedProgram(): void
    {
        $this->updateSelectedProgramParticipation();
    }
}
