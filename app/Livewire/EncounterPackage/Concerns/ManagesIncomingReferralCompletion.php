<?php

declare(strict_types=1);

namespace App\Livewire\EncounterPackage\Concerns;

use App\Classes\eHealth\EHealth;
use App\Enums\JobStatus;
use App\Enums\Person\EncounterStatus;
use App\Exceptions\EHealth\EHealthConnectionException;
use App\Exceptions\EHealth\EHealthException;
use App\Exceptions\EHealth\EHealthValidationException;
use App\Jobs\RemoteEHealthLinksProcessing;
use App\Models\MedicalEvents\Sql\ServiceRequestRequest;
use App\Repositories\MedicalEvents\Repository;
use App\Services\MedicalEvents\Mappers\ServiceRequestRegistryMapper;
use App\Services\MedicalEvents\ReferralRequestLifecycleService;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;

/**
 * Display and complete the incoming referral of an existing encounter.
 * This concern is shared by edit and read-only views and does not build or sign encounter packages.
 */
trait ManagesIncomingReferralCompletion
{
    /**
     * Electronic referral the accepted encounter was based on, taken into work but not redeemed yet.
     *
     * @var string|null
     */
    #[Locked]
    public ?string $referralToRedeemId = null;

    public bool $showReferralRedeemModal = false;

    #[Locked]
    public array $referralCompletionTypes = ['encounter', 'procedure', 'diagnostic_report'];

    public string $referralCompletionType = 'encounter';

    public string $referralCompletionUuid = '';

    public bool $acknowledgeRemainingQuantity = false;

    #[Locked]
    public float $completionRemainingQuantity = 0;

    #[Locked]
    public bool $referralSubmissionPending = false;

    public bool $showReferralStatusModal = false;

    #[Locked]
    public array $referralCompletionJob = [];

    /** Build the incoming referral card from the persisted patient document and this facility's completion job. */
    #[Computed]
    public function incomingReferral(): ?array
    {
        if ($this->personId === null && $this->prepersonId === null) {
            return null;
        }
        $encounter = Repository::encounter()->findForStandaloneDocument($this->encounterId, $this->patient());
        $record = Repository::serviceRequest()->incomingForEncounter($encounter);
        if ($record === null) {
            return null;
        }
        $row = (new ServiceRequestRegistryMapper())->map(
            $record,
            pending: Repository::serviceRequest()->hasPendingSubmission($record),
            canComplete: Repository::serviceRequest()->canComplete($record)
        );
        $submission = Repository::serviceRequest()->latestCompletion($record);

        return array_replace($row, [
            'doctorName' => Repository::serviceRequest()->requesterName($record),
            'createdLabel' => $record->authoredOn
                ? Carbon::parse($record->authoredOn, 'UTC')->setTimezone(config('app.timezone'))->format('d.m.Y H:i')
                : ($record->createdAt?->format('d.m.Y H:i') ?? '—'),
            'canComplete' => $encounter->status === EncounterStatus::FINISHED
                && Auth::user()->can('complete', ServiceRequestRequest::class)
                && Repository::serviceRequest()->canComplete($record),
            'completionTypes' => Repository::serviceRequest()->completionTypes($record),
            'hasCompletion' => $submission !== null,
            'submissionPending' => $submission?->status === JobStatus::PENDING->value,
        ]);
    }

    /** Offer only registered, linked clinical records compatible with the referral category. */
    #[Computed]
    public function referralCompletionResources(): array
    {
        $referral = $this->incomingReferral;

        return $referral === null ? [] : Repository::serviceRequest()->completionResources(
            $this->patient(),
            $referral['uuid'],
            $this->referralCompletionType
        );
    }

    /** Reset the selected clinical result when its resource type changes. */
    public function updatedReferralCompletionType(): void
    {
        unset($this->referralCompletionResources);
        $this->referralCompletionUuid = $this->referralCompletionResources[0]['uuid'] ?? '';
    }

    /** Prepare the completion choice after checking permission, referral state and outstanding submissions. */
    public function openReferralRedeemModal(): void
    {
        if (Auth::user()->cannot('complete', ServiceRequestRequest::class)) {
            Session::flash('error', __('referrals.messages.complete_forbidden'));

            return;
        }
        $referral = $this->incomingReferral;
        if (!($referral['canComplete'] ?? false)) {
            Session::flash('error', __('referrals.messages.action_unavailable'));

            return;
        }
        if ($referral['submissionPending']) {
            $this->referralSubmissionPending = true;
            Session::flash('info', __('care-plan.submission_pending'));

            return;
        }
        $this->referralToRedeemId = $referral['uuid'];
        $this->acknowledgeRemainingQuantity = false;
        $this->completionRemainingQuantity = (float) ($referral['remainingQuantity'] ?? 0);
        $this->referralCompletionTypes = $referral['completionTypes'];
        $this->referralCompletionType = $this->referralCompletionTypes[0];
        foreach (['diagnostic_report', 'procedure', 'encounter'] as $type) {
            if (Repository::serviceRequest()->completionResources($this->patient(), $referral['uuid'], $type) !== []) {
                $this->referralCompletionType = $type;
                break;
            }
        }
        $this->updatedReferralCompletionType();
        $this->showReferralRedeemModal = true;
    }

    /** Poll silently so an unchanged pending result does not produce repeated notifications. */
    public function pollReferralCompletion(ReferralRequestLifecycleService $service): void
    {
        $this->checkReferralCompletion($service, notifyPending: false);
    }

    /** Read the owned completion job, display its outcome and reconcile a newly confirmed result. */
    public function openReferralStatusModal(): void
    {
        if (Auth::user()->cannot('complete', ServiceRequestRequest::class)) {
            Session::flash('error', __('referrals.messages.complete_forbidden'));

            return;
        }

        $encounter = Repository::encounter()->findForStandaloneDocument($this->encounterId, $this->patient());
        $record = Repository::serviceRequest()->incomingForEncounter($encounter);
        $submission = $record === null ? null : Repository::serviceRequest()->latestCompletion($record);
        if ($submission === null) {
            Session::flash('info', __('referrals.messages.completion_job_missing'));

            return;
        }

        $this->showReferralStatusModal = true;
        $this->referralCompletionJob = [
            'id' => $submission->href ? basename($submission->href) : '—',
            'status' => JobStatus::tryFrom($submission->status)?->label() ?? $submission->status,
            'error' => null,
            'refreshError' => null,
        ];

        try {
            $job = $submission->href !== null
                ? EHealth::job()->getDetailsByHref($submission->href)->validate()
                : ($submission->job?->response_data ?? []);
            $pending = in_array(strtolower((string) ($job['status'] ?? '')), ['pending', 'processing', 'accepted', 'queued'], true);
            $this->referralSubmissionPending = $pending;
            $this->referralCompletionJob['status'] = JobStatus::tryFrom(strtoupper($job['status'] ?? ''))?->label()
                ?? $this->referralCompletionJob['status'];
            if (isset($job['error'])) {
                $this->referralCompletionJob['error'] = EHealth::job()->jobError($job)->getFormattedMessage();
            }
            if (!$pending && isset($job['status'])) {
                RemoteEHealthLinksProcessing::finishSubmission(
                    $submission,
                    $job,
                    Auth::user(),
                    legalEntity(),
                    session()->get(config('ehealth.api.oauth.bearer_token'))
                );
            }
        } catch (EHealthException|EHealthConnectionException $exception) {
            $this->referralCompletionJob['refreshError'] = __('referrals.messages.completion_job_refresh_failed');
            $exception->handle('Error when getting referral completion job details');
        } catch (Exception $exception) {
            report($exception);
            $this->referralCompletionJob['refreshError'] = __('referrals.messages.completion_job_refresh_failed');
            Session::flash('error', __('referrals.messages.completion_job_refresh_failed'));
        }

        unset($this->incomingReferral);
    }

    /** Refresh completion tracking without issuing another Complete Service Request mutation. */
    public function checkReferralCompletion(ReferralRequestLifecycleService $service, bool $notifyPending = true): void
    {
        if (Auth::user()->cannot('complete', ServiceRequestRequest::class)) {
            $this->referralSubmissionPending = false;
            Session::flash('error', __('referrals.messages.complete_forbidden'));

            return;
        }
        $encounter = Repository::encounter()->findForStandaloneDocument($this->encounterId, $this->patient());
        $record = Repository::serviceRequest()->incomingForEncounter($encounter);
        if ($record === null) {
            $this->referralSubmissionPending = false;

            return;
        }
        try {
            $response = $service->checkCompletion($record);
            $this->referralSubmissionPending = $response['submissionPending'];
            if ($this->referralSubmissionPending && $notifyPending) {
                Session::flash('info', __('care-plan.submission_pending'));
            } elseif (!$this->referralSubmissionPending && $response['status'] === 'completed') {
                Session::flash('success', __('referrals.messages.complete_success'));
            }
        } catch (EHealthValidationException $exception) {
            $this->referralSubmissionPending = false;
            Session::flash('error', $exception->getFormattedMessage());
        } catch (Exception $exception) {
            $this->referralSubmissionPending = false;
            Session::flash('error', __('referrals.messages.operation_failed'));
        }
        unset($this->incomingReferral);
    }

    /** Offer redemption once the encounter has been successfully registered in ESOZ. */
    protected function promptReferralCompletion(): void
    {
        unset($this->incomingReferral, $this->referralCompletionResources);
        $referral = $this->incomingReferral;
        $this->referralSubmissionPending = $referral['submissionPending'] ?? false;
        if (($referral['canComplete'] ?? false) && !$this->referralSubmissionPending) {
            $this->openReferralRedeemModal();
        }
    }

    /**
     * Redeem the electronic referral the accepted encounter was based on.
     * Require a compatible linked record; an accepted async receipt remains pending until ESOZ confirms completion.
     *
     * @param  ReferralRequestLifecycleService  $service
     * @return void
     */
    public function redeemReferral(ReferralRequestLifecycleService $service): void
    {
        if (Auth::user()->cannot('complete', ServiceRequestRequest::class)) {
            Session::flash('error', __('referrals.messages.complete_forbidden'));

            return;
        }

        try {
            $referral = $this->incomingReferral;
            if (
                !($referral['canComplete'] ?? false)
                || $referral['uuid'] !== $this->referralToRedeemId
                || !in_array($this->referralCompletionUuid, array_column($this->referralCompletionResources, 'uuid'), true)
            ) {
                Session::flash('error', __('care-plan.referral_complete_emz_not_linked'));

                return;
            }

            if ((float) ($referral['remainingQuantity'] ?? 0) > 0 && !$this->acknowledgeRemainingQuantity) {
                Session::flash('warning', __('referrals.remaining_quantity_warning'));

                return;
            }
            $response = $service->completeReferral($referral['uuid'], $this->referralCompletionUuid, $this->referralCompletionType, ['acknowledge_remaining_quantity' => $this->acknowledgeRemainingQuantity]);
            $this->showReferralRedeemModal = false;
            unset($this->incomingReferral);
            if ($response['submissionPending'] ?? false) {
                $this->referralSubmissionPending = true;
                Session::flash('info', __('care-plan.submission_pending'));

                return;
            }
            Session::flash('success', __('encounters.messages.referral_redeemed'));
        } catch (EHealthValidationException $exception) {
            Session::flash('error', $exception->getFormattedMessage());
        } catch (ValidationException $exception) {
            Session::flash('error', $exception->validator->errors()->first());
        } catch (Exception $exception) {
            Session::flash('error', __('encounters.messages.referral_redeem_error', ['message' => $exception->getMessage()]));
        }
        unset($this->incomingReferral);
    }

    /** Restore human referral numbers for saved procedure and diagnostic-report references. */
    private function loadSelectedReferralsForView(array $records): void
    {
        $referralIds = collect($records)
            ->pluck('basedOnIdentifier')
            ->filter()
            ->unique()
            ->values();

        if ($referralIds->isEmpty()) {
            $this->referralsLoaded = true;

            return;
        }

        $numbers = Repository::serviceRequest()->requestNumbersByUuids($referralIds->all());
        $this->availableReferrals = $referralIds
            ->filter(static fn (string $uuid): bool => filled($numbers[$uuid] ?? null))
            ->map(static fn (string $uuid): array => ['id' => $uuid, 'requisition' => $numbers[$uuid]])
            ->values()->all();

        $this->referralsLoaded = true;
    }
}
