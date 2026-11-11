<?php

declare(strict_types=1);

namespace App\Livewire\Components;

use App\Classes\eHealth\EHealth;
use App\Enums\CarePlanStatus;
use App\Enums\Composition\CompositionJobStatus;
use App\Enums\JobStatus;
use App\Exceptions\EHealth\EHealthConnectionException;
use App\Exceptions\EHealth\EHealthException;
use App\Jobs\RemoteEHealthLinksProcessing;
use App\Models\CarePlan;
use App\Models\EhealthLink;
use App\Models\MedicalEvents\Sql\CompositionOperation;
use App\Repositories\MedicalEvents\Repository as MedicalEventsRepository;
use App\Repositories\Repository;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Reactive;
use Livewire\Component;

class SubmissionStatus extends Component
{
    /**
     * Status shown in the block: the last submission status, or the record status without one.
     *
     * @var string
     */
    #[Locked]
    public string $statusLabel;

    /**
     * Job processing the last submission of the signed record, null when the record was never submitted.
     *
     * @var string|null
     */
    #[Locked]
    public ?string $jobId = null;

    /**
     * Last submission of the signed record, null when the record was never submitted.
     *
     * @var int|null
     */
    #[Locked]
    public ?int $submissionId = null;

    /**
     * Edit page of the record while it can still be changed, which the update button leads to.
     *
     * @var string|null
     */
    #[Reactive]
    public ?string $editUrl = null;

    /**
     * Processing details of the submission job, fetched from eHealth each time they are opened.
     *
     * @var array
     */
    #[Locked]
    public array $job = [];

    public bool $showDetails = false;

    #[Locked]
    public ?int $compositionOperationId = null;

    #[Locked]
    public ?int $carePlanId = null;

    #[Locked]
    public ?string $carePlanEditUrl = null;

    /**
     * @param  string  $recordType
     * @param  int  $recordId
     * @param  string  $recordStatusLabel
     * @param  string|null  $editUrl
     * @return void
     */
    public function mount(string $recordType, int $recordId, string $recordStatusLabel, ?string $editUrl = null, ?int $compositionOperationId = null): void
    {
        if ($recordType === CarePlan::class) {
            $this->carePlanId = $recordId;
            $this->refreshCarePlanSubmission(notifyParent: false);

            return;
        }
        if ($compositionOperationId !== null) {
            $this->compositionOperationId = $compositionOperationId;
            $operation = $this->compositionOperation();
            $this->jobId = $operation->remoteJobId;
            $this->statusLabel = CompositionJobStatus::from($operation->status)->label();
            $this->editUrl = null;

            return;
        }
        $submission = EhealthLink::whereLinkableType($recordType)
            ->whereLinkableId($recordId)
            ->latest('id')
            ->first();

        $this->statusLabel = $submission !== null ? (JobStatus::tryFrom($submission->status ?? '')?->label() ?? $recordStatusLabel) : $recordStatusLabel;
        $this->editUrl = $editUrl;

        // A submission eHealth gave no job for has no details to fetch
        if ($submission?->href !== null) {
            $this->jobId = basename($submission->href);
            $this->submissionId = $submission->id;
        }
    }

    /**
     * Fetch the current processing details of the submission job from eHealth and show them.
     *
     * @return void
     */
    public function openDetails(): void
    {
        if ($this->carePlanId !== null && !$this->refreshCarePlanSubmission()) {
            return;
        }
        if ($this->jobId === null) {
            return;
        }
        if ($this->compositionOperationId !== null) {
            $operation = $this->compositionOperation();
            MedicalEventsRepository::compositionOperation()->queue($operation);
            $this->statusLabel = CompositionJobStatus::from($operation->status)->label();
            $this->job = [
                'id' => $operation->remoteJobId,
                'status' => $this->statusLabel,
                'created' => $operation->composition ? [['entity' => __('compositions.medical_conclusion'), 'id' => $operation->composition->uuid]] : [],
                'error' => $operation->error,
            ];
            $this->showDetails = true;
            $this->dispatch('composition-submission-updated');

            return;
        }
        try {
            $job = EHealth::job()->getDetails($this->jobId)->validate();
        } catch (EHealthException|EHealthConnectionException $exception) {
            $exception->handle('Error when getting submission job details');

            return;
        } catch (ValidationException) {
            Session::flash('error', __('errors.ehealth.messages.request_error'));

            return;
        }

        $this->job = [
            'id' => $this->jobId,
            'status' => JobStatus::tryFrom(strtoupper($job['status']))?->label() ?? $job['status'],
            'created' => array_map(static fn (array $link): array => [
                'entity' => Lang::has("encounters.submission.entities.{$link['entity']}")
                    ? __("encounters.submission.entities.{$link['entity']}")
                    : $link['entity'],
                'id' => basename($link['href'])
            ], $job['links'] ?? []),
            'error' => isset($job['error']) ? EHealth::job()->jobError($job)->getFormattedMessage() : null
        ];

        $this->statusLabel = $this->job['status'];
        $this->showDetails = true;

        if (in_array(strtolower($job['status']), ['pending', 'processing', 'accepted'], true)) {
            return;
        }

        RemoteEHealthLinksProcessing::finishSubmission(EhealthLink::findOrFail($this->submissionId), $job, Auth::user(), legalEntity(), session()->get(config('ehealth.api.oauth.bearer_token')));

        $this->dispatch(
            JobStatus::tryFrom(strtoupper($job['status'])) === JobStatus::PROCESSED
                ? 'submission-processed'
                : 'submission-rejected'
        );
    }

    public function render(): View
    {
        return view('livewire.components.submission-status', [
            'recordEditUrl' => $this->carePlanId !== null ? $this->carePlanEditUrl : $this->editUrl,
        ]);
    }

    public function checkStatus(): void
    {
        if ($this->carePlanId !== null) {
            $this->refreshCarePlanSubmission();
        }
    }

    protected function refreshCarePlanSubmission(bool $notifyParent = true): bool
    {
        $snapshot = Repository::carePlan()->submissionForDisplay($this->carePlanId);
        $plan = $snapshot['plan'];
        abort_unless($plan !== null, 404);
        if (Auth::user()->cannot('view', $plan)) {
            $this->statusLabel = __('care-plan.sync_access_denied');
            $this->jobId = null;
            $this->submissionId = null;
            $this->carePlanEditUrl = null;
            $this->job = [];
            $this->showDetails = false;
            Session::flash('error', __('care-plan.sync_access_denied'));

            return false;
        }
        $submission = $snapshot['submission'];
        $this->statusLabel = JobStatus::tryFrom($submission['status'] ?? '')?->label() ?? CarePlanStatus::labelFor($plan->status);
        $this->submissionId = $submission['id'];
        $this->jobId = $snapshot['jobId'];
        $canEdit = CarePlanStatus::fromStored($plan->status) === CarePlanStatus::DRAFT
            && !in_array($submission['status'], [JobStatus::PENDING->value, JobStatus::PROCESSING->value, JobStatus::PROCESSED->value], true)
            && Auth::user()->can('update', $plan);
        $this->carePlanEditUrl = $canEdit ? route('care-plans.edit', [legalEntity(), $plan->id]) : null;
        if ($notifyParent) {
            $this->dispatch('care-plan-submission-status-updated');
        }

        return true;
    }

    protected function compositionOperation(): CompositionOperation
    {
        return MedicalEventsRepository::compositionOperation()->findForStatus($this->compositionOperationId, Auth::user());
    }
}
