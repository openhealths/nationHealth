<?php

declare(strict_types=1);

namespace App\Livewire\CarePlan;

use App\Livewire\CarePlan\Concerns\CarePlanManager;
use App\Livewire\CarePlan\Concerns\ManagesCarePlanActivities;
use App\Livewire\CarePlan\Concerns\ManagesCarePlanEPrescription;
use App\Livewire\CarePlan\Concerns\ManagesCarePlanReferrals;
use App\Models\CarePlan;
use App\Repositories\CarePlanActivityRepository;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;

class CarePlanShow extends CarePlanComponent
{
    use CarePlanManager;
    use ManagesCarePlanActivities;
    use ManagesCarePlanEPrescription;
    use ManagesCarePlanReferrals;

    #[Locked]
    public array $remotePlanDetails = [];

    public function mount(CarePlan $carePlan): void
    {
        $snapshot = app(\App\Repositories\CarePlanRepository::class)->submissionForDisplay($carePlan->id);
        abort_unless($snapshot['plan'] instanceof CarePlan, 404);
        $this->bootCarePlan($snapshot['plan']);
        $this->planSubmission = $snapshot['submission'];
        $this->refreshInpatientApprovalStatus();
        $this->refreshDisplayedDetails();
        $this->activateAfterSubmission = true;
        $this->continuePlanActivation();
        if (\Illuminate\Support\Facades\Session::get('info') === __('care-plan.submission_pending')
            && in_array($this->planSubmission['status'], [\App\Enums\JobStatus::PROCESSED->value, \App\Enums\JobStatus::FAILED->value], true)) {
            \Illuminate\Support\Facades\Session::forget('info');
            \Illuminate\Support\Facades\Session::flash(
                $this->planSubmission['status'] === \App\Enums\JobStatus::PROCESSED->value ? 'success' : 'error',
                $this->planSubmission['status'] === \App\Enums\JobStatus::PROCESSED->value ? __('care-plan.submission_confirmed') : ($this->planSubmission['error'] ?: __('care-plan.submission_failed_edit_draft'))
            );
        }

        $editActivityId = request()->query('edit_activity');
        if (is_numeric($editActivityId)) {
            $this->editActivity((int) $editActivityId, app(CarePlanActivityRepository::class));
        }

        $this->activityForm['scheduled_period_end'] = now()->addDays(10)->format('d.m.Y');
    }

    #[On('submission-processed')]
    #[On('submission-rejected')]
    #[On('care-plan-submission-status-updated')]
    public function checkPlanSubmissionStatus(): void
    {
        $repository = app(\App\Repositories\CarePlanRepository::class);
        $previousStatus = $this->planSubmission['status'] ?? null;
        $this->planSubmission = $repository->submissionState($this->carePlan);
        $this->refreshCarePlan();
        if ($previousStatus !== $this->planSubmission['status'] && $this->planSubmission['status'] === \App\Enums\JobStatus::FAILED->value) {
            \Illuminate\Support\Facades\Session::flash('error', $this->planSubmission['error'] ?: __('care-plan.submission_failed_edit_draft'));
        } elseif ($previousStatus !== $this->planSubmission['status'] && $this->planSubmission['status'] === \App\Enums\JobStatus::PROCESSED->value) {
            \Illuminate\Support\Facades\Session::flash('success', __('care-plan.submission_confirmed'));
        }
        $this->continuePlanActivation();
    }

    public function hydrate(): void
    {
        parent::hydrate();
        $this->checkPlanSubmissionStatus();
    }

    protected function renderCarePlan()
    {
        return view('livewire.care-plan.care-plan-show', [
            'addressConditions' => app(\App\Repositories\CarePlanRepository::class)->addressConditions($this->carePlan),
        ]);
    }

    protected function refreshCarePlan(): void
    {
        parent::refreshCarePlan();
        $this->planSubmission = app(\App\Repositories\CarePlanRepository::class)->submissionState($this->carePlan);
        $this->refreshInpatientApprovalStatus();
        $this->refreshDisplayedDetails();
    }

    protected function refreshInpatientApprovalStatus(): void
    {
        $progress = app(\App\Repositories\CarePlanRepository::class)->inpatientApprovalProgress($this->carePlan);
        $previousStatus = $this->inpatientApprovalStatus;
        $this->inpatientApprovalStatus = $progress['status'] ?? null;
        if (in_array($this->inpatientApprovalStatus, ['queued', 'pending', 'failed'], true) && $this->hasGrantedApprovalForCurrentDoctor) {
            $this->inpatientApprovalStatus = 'granted';
        } elseif ($this->inpatientApprovalStatus === 'pending' && !empty($progress['linkId'])) {
            $result = app(\App\Services\MedicalEvents\CarePlanApprovalService::class)->resolveAsyncJob($progress['linkId']);
            if ($result->isGranted()) {
                $this->inpatientApprovalStatus = 'granted';
            } elseif ($result->isFailed()) {
                $this->inpatientApprovalStatus = 'failed';
            }
        }
        if ($previousStatus !== $this->inpatientApprovalStatus && in_array($this->inpatientApprovalStatus, ['granted', 'failed'], true)) {
            $this->carePlan->load('approvals.grantedTo.type.coding');
            $this->dispatch('refreshApprovals');
            if (in_array($previousStatus, ['queued', 'pending'], true)) {
                \Illuminate\Support\Facades\Session::flash(
                    $this->inpatientApprovalStatus === 'granted' ? 'success' : 'error',
                    __($this->inpatientApprovalStatus === 'granted' ? 'care-plan.approval_inpatient_granted' : 'care-plan.inpatient_access_failed')
                );
            }
        }
    }
}
