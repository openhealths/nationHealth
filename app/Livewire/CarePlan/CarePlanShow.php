<?php

declare(strict_types=1);

namespace App\Livewire\CarePlan;

use App\Enums\JobStatus;

use App\Livewire\CarePlan\Concerns\CarePlanManager;
use App\Livewire\CarePlan\Concerns\ManagesCarePlanActivities;
use App\Livewire\CarePlan\Concerns\ManagesCarePlanEPrescription;

use App\Livewire\CarePlan\Concerns\ManagesCarePlanReferrals;
use App\Models\CarePlan;
use App\Repositories\Repository;
use App\Services\MedicalEvents\CarePlanApprovalService;
use Illuminate\Support\Facades\Session;
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
        $snapshot = Repository::carePlan()->submissionForDisplay($carePlan->id);
        abort_unless($snapshot['plan'] instanceof CarePlan, 404);
        $this->bootCarePlan($snapshot['plan']);
        $this->planSubmission = $snapshot['submission'];
        $this->refreshInpatientApprovalStatus();
        $this->refreshDisplayedDetails();
        $this->activateAfterSubmission = true;
        $this->continuePlanActivation();
        if (Session::get('info') === __('care-plan.submission_pending')
            && in_array($this->planSubmission['status'], [JobStatus::PROCESSED->value, JobStatus::FAILED->value], true)) {
            Session::forget('info');
            Session::flash(
                $this->planSubmission['status'] === JobStatus::PROCESSED->value ? 'success' : 'error',
                $this->planSubmission['status'] === JobStatus::PROCESSED->value ? __('care-plan.submission_confirmed') : ($this->planSubmission['error'] ?: __('care-plan.submission_failed_edit_draft'))
            );
        }

        $editActivityId = request()->query('edit_activity');
        if (is_numeric($editActivityId)) {
            $this->editActivity((int) $editActivityId);
        }

        $this->activityForm['scheduled_period_end'] = now()->addDays(10)->format('d.m.Y');
    }

    #[On('submission-processed')]
    #[On('submission-rejected')]
    #[On('care-plan-submission-status-updated')]
    public function checkPlanSubmissionStatus(): void
    {
        $repository = Repository::carePlan();
        $previousStatus = $this->planSubmission['status'] ?? null;
        $this->planSubmission = $repository->submissionState($this->carePlan);
        $this->refreshCarePlan();
        if ($previousStatus !== $this->planSubmission['status'] && $this->planSubmission['status'] === JobStatus::FAILED->value) {
            Session::flash('error', $this->planSubmission['error'] ?: __('care-plan.submission_failed_edit_draft'));
        } elseif ($previousStatus !== $this->planSubmission['status'] && $this->planSubmission['status'] === JobStatus::PROCESSED->value) {
            Session::flash('success', __('care-plan.submission_confirmed'));
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
            'addressConditions' => Repository::carePlan()->addressConditions($this->carePlan),
        ]);
    }

    protected function refreshCarePlan(): void
    {
        parent::refreshCarePlan();
        $this->planSubmission = Repository::carePlan()->submissionState($this->carePlan);
        $this->refreshInpatientApprovalStatus();
        $this->refreshDisplayedDetails();
    }

    protected function refreshInpatientApprovalStatus(): void
    {
        $progress = Repository::carePlan()->inpatientApprovalProgress($this->carePlan);
        $previousStatus = $this->inpatientApprovalStatus;
        $this->inpatientApprovalStatus = $progress['status'] ?? null;
        if (in_array($this->inpatientApprovalStatus, ['queued', 'pending', 'failed'], true) && $this->hasGrantedApprovalForCurrentDoctor) {
            $this->inpatientApprovalStatus = 'granted';
        } elseif ($this->inpatientApprovalStatus === 'pending' && !empty($progress['linkId'])) {
            $result = app(CarePlanApprovalService::class)->resolveAsyncJob($progress['linkId']);
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
                Session::flash(
                    $this->inpatientApprovalStatus === 'granted' ? 'success' : 'error',
                    __($this->inpatientApprovalStatus === 'granted' ? 'care-plan.approval_inpatient_granted' : 'care-plan.inpatient_access_failed')
                );
            }
        }
    }
}
