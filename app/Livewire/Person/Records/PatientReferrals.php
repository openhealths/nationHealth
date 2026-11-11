<?php

declare(strict_types=1);

namespace App\Livewire\Person\Records;

use App\Classes\eHealth\EHealth as SubmissionApi;
use App\Core\Arr;
use App\Core\BaseForm as Form;

use App\Enums\JobStatus;
use App\Enums\Person\ServiceRequestStatus;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Exceptions\EHealth\EHealthValidationException;
use App\Jobs\ServiceRequestSync;
use App\Livewire\Concerns\ReadsClinicalSnapshots;
use App\Livewire\Concerns\SubmitsClinicalOperations;
use App\Livewire\Referral\Forms\ServiceRequestForm;
use App\Models\CarePlan;
use App\Models\CarePlanActivity;
use App\Models\Declaration;
use App\Models\MedicalEvents\Sql\Approval;
use App\Models\MedicalEvents\Sql\DeviceRequestRequest;
use App\Models\MedicalEvents\Sql\Encounter;
use App\Models\MedicalEvents\Sql\ServiceRequestRequest;
use App\Models\User;
use App\Notifications\RemoteEHealthLinksNotification;
use App\Repositories\MedicalEvents\Repository as MedicalEventsRepository;
use App\Services\MedicalEvents\Mappers\ServiceRequestMapper;
use App\Services\MedicalEvents\MedicalRequestOwnership;
use App\Services\MedicalEvents\ReferralRequestLifecycleService;
use App\Traits\BatchLegalEntityQueries;
use Illuminate\Bus\Batch;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\WithFileUploads;
use RuntimeException;
use Throwable;

class PatientReferrals extends BasePatientComponent
{
    use BatchLegalEntityQueries;
    use ReadsClinicalSnapshots;
    use SubmitsClinicalOperations;

    use WithFileUploads;

    public Form $form;

    #[Locked]
    public ?string $syncBatchId = null;

    public bool $showCompletedResourceModal = false;

    #[Locked]
    public array $completedResource = [];

    public bool $showReferralRedeemModal = false;

    #[Locked]
    public ?string $referralToRedeemId = null;

    #[Locked]
    public array $referralCompletionTypes = ['encounter', 'procedure', 'diagnostic_report'];

    public string $referralCompletionType = 'encounter';

    public string $referralCompletionUuid = '';

    public bool $acknowledgeRemainingQuantity = false;

    #[Locked]
    public float $completionRemainingQuantity = 0;

    #[Locked]
    public array $pendingCompletions = [];

    public bool $showSignatureModal = false;

    public ?string $actionType = null;

    /** @var list<array<string, mixed>> */
    public array $referrals = [];

    public string $filterStatus = '';

    public string $filterStartedAtFrom = '';

    public string $filterStartedAtTo = '';

    public string $filterEndedAtFrom = '';

    public string $filterEndedAtTo = '';

    public ?string $expandedUuid = null;

    public ?string $requestIdToSign = null;

    public string $requestKindToSign = 'service_request';

    public string $referralExplanatoryLetter = '';
    public string $referralStatusReason = '';
    #[Locked]
    public array $referralStatusReasons = [];

    #[Locked]
    public ?string $registryPrintUuid = null;

    protected function initializeComponent(): void
    {
        $this->loadReferrals();
        $this->syncBatchId = $this->findRunningBatchesByLegalEntity(legalEntity()->id)
            ->firstWhere('name', ServiceRequestSync::BATCH_NAME.'_'.$this->uuid)?->id;
        $uuid = request()->query('referral');
        $action = request()->query('referral_action');
        if (is_string($uuid) && in_array($action, ['view', 'print', 'recall', 'error'], true)) {
            $record = $this->ownedReferral($uuid);
            if ($record instanceof ServiceRequestRequest && Auth::user()->can('viewRecord', $record)) {
                $this->expandedUuid = $uuid;
                if ($action === 'recall') {
                    $this->recallReferral($uuid, 'service_request');
                } elseif ($action === 'error') {
                    $this->cancelReferral($uuid, 'service_request');
                } elseif ($action === 'print') {
                    $this->registryPrintUuid = $uuid;
                }
            } else {
                Session::flash('error', __('referrals.messages.action_unavailable'));
            }
        }
    }

    public function loadReferrals(): void
    {
        if ($this->personId === null) {
            $this->referrals = [];

            return;
        }

        $this->validate([
            'filterStartedAtFrom' => ['nullable', 'date_format:Y-m-d'],
            'filterStartedAtTo' => ['nullable', 'date_format:Y-m-d'],
            'filterEndedAtFrom' => ['nullable', 'date_format:Y-m-d'],
            'filterEndedAtTo' => ['nullable', 'date_format:Y-m-d'],
        ], attributes: [
            'filterStartedAtFrom' => __('forms.filter_period_start_range'),
            'filterStartedAtTo' => __('forms.filter_period_start_range'),
            'filterEndedAtFrom' => __('forms.filter_period_end_range'),
            'filterEndedAtTo' => __('forms.filter_period_end_range'),
        ]);

        $filters = [
            'status' => $this->filterStatus !== '' ? $this->filterStatus : null,
            'started_at_from' => $this->filterStartedAtFrom !== '' ? $this->filterStartedAtFrom : null,
            'started_at_to' => $this->filterStartedAtTo !== '' ? $this->filterStartedAtTo : null,
            'ended_at_from' => $this->filterEndedAtFrom !== '' ? $this->filterEndedAtFrom : null,
            'ended_at_to' => $this->filterEndedAtTo !== '' ? $this->filterEndedAtTo : null,
        ];

        $rows = array_merge(
            MedicalEventsRepository::serviceRequest()->searchByPersonId($this->personId, $filters),
            MedicalEventsRepository::deviceRequest()->searchByPersonId($this->personId, $filters),
        );

        usort(
            $rows,
            static function (array $left, array $right): int {
                $leftDate = (string) ($left['startedAt'] ?? '');
                $rightDate = (string) ($right['startedAt'] ?? '');

                return [$rightDate, (int) ($right['id'] ?? 0)] <=> [$leftDate, (int) ($left['id'] ?? 0)];
            }
        );

        $states = $rows === [] ? [] : MedicalEventsRepository::serviceRequest()->completionStates(array_column($rows, 'uuid'));
        $this->pendingCompletions = [];
        foreach ($rows as &$row) {
            $row['hasCompletion'] = isset($states[$row['uuid']]);
            if (($states[$row['uuid']] ?? null) === JobStatus::PENDING->value) {
                $this->pendingCompletions[$row['uuid']] = true;
            }
            $row['canViewCompletedResource'] = $row['kind'] === 'service_request'
                && ($row['status'] === 'completed' || ($row['programProcessingStatus'] ?? null) === 'completed');
            $row['canComplete'] = $row['kind'] === 'service_request'
                && !isset($this->pendingCompletions[$row['uuid']])
                && ($row['canComplete'] ?? false);
        }
        unset($row);
        $this->referrals = $rows;
    }

    /** Issuers may read the linked result after another facility completed the referral. */
    public function viewCompletedResource(string $uuid): void
    {
        if (Auth::user()->cannot('search', ServiceRequestRequest::class)) {
            Session::flash('error', __('referrals.messages.completed_resource_forbidden'));

            return;
        }
        try {
            $record = MedicalEventsRepository::serviceRequest()->completionForPerson($uuid, $this->personId);
            // Check the local issuer and terminal state before any remote read.
            $types = array_filter(['encounter', 'procedure', 'diagnostic_report'], fn (string $type): bool => Auth::user()->can('viewCompletedResource', [ServiceRequestRequest::class, $record, $type]));
            if ($types === []) {
                Session::flash('error', __('referrals.messages.completed_resource_forbidden'));

                return;
            }
            $snapshot = SubmissionApi::serviceRequest()->getById($this->patient()->uuid, $uuid, preserveApiKeys: true)->validate();
            $snapshot = array_is_list($snapshot) ? $snapshot[0] : $snapshot;
            $resourceId = data_get($snapshot, 'completed_with.identifier.value');
            $type = data_get($snapshot, 'completed_with.identifier.type.coding.0.code');
            if (($snapshot['id'] ?? null) !== $uuid
                || data_get($snapshot, 'subject.identifier.value') !== $this->patient()->uuid
                || data_get($snapshot, 'requester_legal_entity.identifier.value') !== legalEntity()->uuid
                || (($snapshot['status'] ?? null) !== 'completed' && ($snapshot['program_processing_status'] ?? null) !== 'completed')
                || !in_array($type, $types, true) || !is_string($resourceId)) {
                Session::flash('error', __('referrals.messages.completed_resource_forbidden'));

                return;
            }
            $response = match ($type) {
                'encounter' => SubmissionApi::encounter()->getById($this->patient()->uuid, $resourceId),
                'procedure' => SubmissionApi::procedure()->getById($this->patient()->uuid, $resourceId),
                'diagnostic_report' => SubmissionApi::diagnosticReport()->getById($this->patient()->uuid, $resourceId),
            };
            $resource = $response->validate();
            $resource = array_is_list($resource) ? $resource[0] : $resource;
            $resource = Arr::toSnakeCase($resource);
            // The encounter GET contract binds the patient through its URL and does not return a subject field.
            $subjectUuid = data_get($resource, 'subject.identifier.value');
            if (($resource['id'] ?? ($resource['uuid'] ?? null)) !== $resourceId
                || ($subjectUuid !== null && $subjectUuid !== $this->patient()->uuid)) {
                throw ValidationException::withMessages(['completedResource' => __('referrals.messages.completed_resource_forbidden')]);
            }
            // A foreign facility's EMZ is displayed as a validated read-only snapshot, without importing its package.
            $this->completedResource = $resource;
            $this->showCompletedResourceModal = true;
        } catch (Throwable $exception) {
            report($exception);
            Session::flash('error', __('referrals.messages.completed_resource_failed'));
        }
    }

    #[Computed]
    public function referralCompletionResources(): array
    {
        if ($this->referralToRedeemId === null || $this->personId === null) {
            return [];
        }
        MedicalEventsRepository::serviceRequest()->completionForPerson($this->referralToRedeemId, $this->personId);

        return MedicalEventsRepository::serviceRequest()->completionResources(
            $this->patient(),
            $this->referralToRedeemId,
            $this->referralCompletionType
        );
    }

    public function updatedReferralCompletionType(): void
    {
        unset($this->referralCompletionResources);
        $this->referralCompletionUuid = $this->referralCompletionResources[0]['uuid'] ?? '';
    }

    public function openReferralRedeemModal(string $uuid): void
    {
        if (Auth::user()->cannot('complete', ServiceRequestRequest::class)) {
            Session::flash('error', __('referrals.messages.complete_forbidden'));

            return;
        }
        $record = MedicalEventsRepository::serviceRequest()->completionForPerson($uuid, $this->personId);
        if (!MedicalEventsRepository::serviceRequest()->canComplete($record)) {
            Session::flash('error', __('referrals.messages.action_unavailable'));

            return;
        }
        if (MedicalEventsRepository::serviceRequest()->hasPendingSubmission($record)) {
            Session::flash('info', __('care-plan.submission_pending'));

            return;
        }
        $this->referralToRedeemId = $uuid;
        $this->acknowledgeRemainingQuantity = false;
        $this->completionRemainingQuantity = (float) ($record->remainingQuantity?->value ?? 0);
        $this->referralCompletionTypes = MedicalEventsRepository::serviceRequest()->completionTypes($record);
        $this->referralCompletionType = $this->referralCompletionTypes[0];
        foreach (['diagnostic_report', 'procedure', 'encounter'] as $type) {
            if (MedicalEventsRepository::serviceRequest()->completionResources($this->patient(), $uuid, $type) !== []) {
                $this->referralCompletionType = $type;
                break;
            }
        }
        $this->updatedReferralCompletionType();
        $this->showReferralRedeemModal = true;
    }

    public function redeemReferral(ReferralRequestLifecycleService $service): void
    {
        if (Auth::user()->cannot('complete', ServiceRequestRequest::class)) {
            Session::flash('error', __('referrals.messages.complete_forbidden'));

            return;
        }
        if ($this->referralToRedeemId === null
            || !in_array($this->referralCompletionUuid, array_column($this->referralCompletionResources, 'uuid'), true)) {
            Session::flash('error', __('care-plan.referral_complete_emz_not_linked'));

            return;
        }
        $record = MedicalEventsRepository::serviceRequest()->completionForPerson($this->referralToRedeemId, $this->personId);
        if (!MedicalEventsRepository::serviceRequest()->canComplete($record)) {
            Session::flash('error', __('referrals.messages.action_unavailable'));

            return;
        }
        try {
            if ((float) ($record->remainingQuantity?->value ?? 0) > 0 && !$this->acknowledgeRemainingQuantity) {
                Session::flash('warning', __('referrals.remaining_quantity_warning'));

                return;
            }
            $response = $service->completeReferral($record->uuid, $this->referralCompletionUuid, $this->referralCompletionType, ['acknowledge_remaining_quantity' => $this->acknowledgeRemainingQuantity]);
            $this->showReferralRedeemModal = false;
            Session::flash(($response['submissionPending'] ?? false) ? 'info' : 'success', __(
                ($response['submissionPending'] ?? false) ? 'care-plan.submission_pending' : 'referrals.messages.complete_success'
            ));
        } catch (EHealthValidationException $exception) {
            Session::flash('error', $exception->getFormattedMessage());
        } catch (ValidationException $exception) {
            Session::flash('error', $exception->validator->errors()->first());
        } catch (Throwable $exception) {
            Session::flash('error', __('referrals.messages.operation_failed'));
        }
        $this->loadReferrals();
    }

    public function checkPendingCompletions(): void
    {
        foreach (array_keys($this->pendingCompletions) as $uuid) {
            $this->checkReferralCompletion($uuid);
        }
    }

    public function checkReferralCompletion(string $uuid): void
    {
        if (Auth::user()->cannot('complete', ServiceRequestRequest::class)) {
            $this->pendingCompletions = [];
            Session::flash('error', __('referrals.messages.complete_forbidden'));

            return;
        }
        $record = MedicalEventsRepository::serviceRequest()->completionForPerson($uuid, $this->personId);
        if (!$record instanceof ServiceRequestRequest) {
            return;
        }
        try {
            $response = app(ReferralRequestLifecycleService::class)->checkCompletion($record);
            if ($response['submissionPending']) {
                return;
            }
            if ($response['status'] === 'completed') {
                Session::flash('success', __('referrals.messages.complete_success'));
            }
        } catch (EHealthValidationException $exception) {
            Session::flash('error', $exception->getFormattedMessage());
        } catch (Throwable $exception) {
            unset($this->pendingCompletions[$uuid]);
            Session::flash('error', __('referrals.messages.operation_failed'));

            return;
        }
        $this->loadReferrals();
    }

    public function applyFilters(): void
    {
        $this->loadReferrals();
    }

    public function sync(): void
    {
        if (Auth::user()->cannot('search', ServiceRequestRequest::class)) {
            Session::flash('error', __('service-requests.policy.search'));

            return;
        }
        if ($this->personId === null) {
            return;
        }

        $token = Session::get(config('ehealth.api.oauth.bearer_token'));
        if (!is_string($token) || $token === '') {
            Session::flash('error', __('referrals.messages.sync_session_expired'));

            return;
        }
        $legalEntity = legalEntity();
        $name = ServiceRequestSync::BATCH_NAME.'_'.$this->uuid;
        $lock = Cache::lock('referral-sync:'.$legalEntity->id.':'.$this->uuid, 120);
        if (!$lock->get()) {
            Session::flash('info', __('referrals.messages.sync_running'));

            return;
        }
        try {
            $running = $this->findRunningBatchesByLegalEntity($legalEntity->id)->firstWhere('name', $name);
            if ($running !== null) {
                $this->syncBatchId = $running->id;
                Session::flash('info', __('referrals.messages.sync_running'));

                return;
            }
            $hasPatientAccess = Declaration::accessGrantedTo(Auth::user(), $this->patient(), $legalEntity)->exists()
                || Approval::accessGrantedTo(Auth::user(), $this->patient(), $legalEntity)->exists();
            // ABAC rule 2 requires the requesting organization in the query without full patient access.
            $query = ['page_size' => 100];
            if (!$hasPatientAccess) {
                $query['requester_legal_entity'] = $legalEntity->uuid;
            }
            $response = SubmissionApi::serviceRequest()->withToken($token)->getBySearchParams($this->uuid, $query + ['status' => 'active', 'page' => 1]);
            $jobs = ServiceRequestSync::pageJobs($response, 1, 'active');
            foreach (['completed', 'entered_in_error', 'recalled'] as $status) {
                $jobs[] = new ServiceRequestSync(status: $status);
            }
            $connection = (string) config('queue.default');
            if (config('queue.connections.'.$connection.'.driver') === 'sync') {
                $connection = 'database';
            }
            $userId = Auth::id();
            $this->syncBatchId = Bus::batch($jobs)->name($name)
                ->withOption('legal_entity_id', $legalEntity->id)
                ->withOption('patient_uuid', $this->uuid)
                ->withOption('person_id', $this->personId)
                ->withOption('query', $query)
                ->withOption('token', Crypt::encryptString($token))
                ->withOption('user', Auth::user())
                ->then(static function (Batch $batch) use ($userId): void {
                    $denied = (int) Cache::get('referral-sync-denied:'.$batch->id, 0);
                    User::find($userId)?->notify(new RemoteEHealthLinksNotification(
                        __($denied > 0 ? 'referrals.messages.sync_partial_access' : 'referrals.messages.sync_success', ['count' => $denied]),
                        $denied > 0 ? 'warning' : 'success'
                    ));
                })
                ->catch(static function (Batch $batch, Throwable $exception) use ($userId): void {
                    $error = match ($exception->getCode()) {
                        401 => 'sync_session_expired',
                        403 => 'sync_access_denied',
                        default => 'sync_failed',
                    };
                    Cache::put('referral-sync-error:'.$batch->id, $error, now()->addDay());
                    Log::warning('Patient referral synchronization batch failed', ['batchId' => $batch->id, 'exceptionType' => $exception::class]);
                    User::find($userId)?->notify(new RemoteEHealthLinksNotification(__('referrals.messages.'.$error), 'error'));
                })
                ->onConnection($connection)->onQueue('sync')->dispatch()->id;
            Session::flash('info', __($hasPatientAccess ? 'referrals.messages.sync_started' : 'referrals.messages.sync_started_own'));
        } catch (EHealthResponseException $exception) {
            Session::flash('error', __('referrals.messages.'.match ($exception->getCode()) {
                401 => 'sync_session_expired',
                403 => 'sync_access_denied',
                default => 'sync_failed',
            }));
        } catch (EHealthValidationException $exception) {
            Session::flash('error', $exception->getFormattedMessage());
        } catch (Throwable $exception) {
            Log::warning('Patient referral synchronization failed', ['exceptionType' => $exception::class]);
            Session::flash('error', __('referrals.messages.sync_failed'));
        } finally {
            $lock->release();
        }
        $this->loadReferrals();
    }

    public function checkSyncStatus(): void
    {
        if (Auth::user()->cannot('search', ServiceRequestRequest::class)) {
            $this->syncBatchId = null;
            Session::flash('error', __('service-requests.policy.search'));

            return;
        }
        if ($this->syncBatchId === null) {
            return;
        }
        $batch = Bus::findBatch($this->syncBatchId);
        if ($batch !== null && !$batch->finished() && !$batch->cancelled()) {
            $this->loadReferrals();

            return;
        }
        $error = Cache::get('referral-sync-error:'.$this->syncBatchId, 'sync_failed');
        $denied = (int) Cache::get('referral-sync-denied:'.$this->syncBatchId, 0);
        $this->syncBatchId = null;
        $this->loadReferrals();
        if ($batch === null || $batch->hasFailures() || $batch->cancelled()) {
            Session::flash('error', __('referrals.messages.'.$error));
        } elseif ($denied > 0) {
            Session::flash('warning', __('referrals.messages.sync_partial_access', ['count' => $denied]));
        } else {
            Session::flash('success', __('referrals.messages.sync_success'));
        }
    }

    public function resetFilters(): void
    {
        $this->reset([
            'filterStatus',
            'filterStartedAtFrom',
            'filterStartedAtTo',
            'filterEndedAtFrom',
            'filterEndedAtTo',
        ]);
        $this->resetValidation();
        $this->loadReferrals();
    }

    public function toggleDetails(string $uuid): void
    {
        $this->expandedUuid = $this->expandedUuid === $uuid ? null : $uuid;
    }

    public function openSign(string $uuid, string $kind): void
    {
        $record = $this->ownedReferral($uuid);
        if ($record instanceof DeviceRequestRequest) {
            $this->redirectRoute('persons.device-requests.create', ['legalEntity' => legalEntity(), 'person' => $this->patient(), 'draft' => $uuid]);

            return;
        }
        $this->requestIdToSign = $uuid;
        $this->requestKindToSign = 'service_request';
        $this->actionType = 'sign_referral';
        $this->showSignatureModal = true;
    }

    public function sign(): void
    {
        if (in_array($this->actionType, ['sign_referral', 'sign_devicerequest'], true)) {
            $this->signDraft();

            return;
        }

        if ($this->actionType === 'recall_referral') {
            $this->signRecallReferral();

            return;
        }

        if ($this->actionType === 'cancel_referral') {
            $this->signCancelReferral();
        }
    }

    public function recallReferral(string $uuid, string $kind): void
    {
        $record = $this->ownedReferral($uuid);
        if ($kind !== 'service_request') {
            Session::flash('error', __('care-plan.referral_recall_service_only'));

            return;
        }

        if (!$record instanceof ServiceRequestRequest || Auth::user()->cannot('recall', $record)) {
            Session::flash('error', __('referrals.messages.action_unavailable'));

            return;
        }

        $this->requestIdToSign = $uuid;
        $this->requestKindToSign = 'service_request';
        $this->referralExplanatoryLetter = '';
        $this->loadReferralStatusReasons('recall');
        $this->actionType = 'recall_referral';
        $this->showSignatureModal = true;
    }

    public function cancelReferral(string $uuid, string $kind): void
    {
        $record = $this->ownedReferral($uuid);
        if ($record instanceof DeviceRequestRequest) {
            $this->redirectRoute('persons.device-requests', ['legalEntity' => legalEntity(), 'person' => $this->patient()]);

            return;
        }
        if (Auth::user()->cannot('cancel', $record)) {
            Session::flash('error', __('referrals.messages.action_unavailable'));

            return;
        }
        $this->requestIdToSign = $uuid;
        $this->requestKindToSign = 'service_request';
        $this->loadReferralStatusReasons('cancel');
        $this->actionType = 'cancel_referral';
        $this->showSignatureModal = true;
    }

    public function signRecallReferral(): void
    {
        if (empty($this->requestIdToSign)) {
            Session::flash('error', 'Не вибрано направлення для відкликання');
            $this->showSignatureModal = false;

            return;
        }

        $letter = trim($this->referralExplanatoryLetter);
        if ($letter === '') {
            $this->addError('referralExplanatoryLetter', __('care-plan.referral_recall_letter_required'));

            return;
        }

        $record = $this->ownedReferral((string) $this->requestIdToSign);
        if (!$record instanceof ServiceRequestRequest) {
            Session::flash('error', __('care-plan.referral_recall_service_only'));
            $this->showSignatureModal = false;

            return;
        }

        if (Auth::user()->cannot('recall', $record)) {
            Session::flash('error', __('referrals.messages.action_unavailable'));

            return;
        }

        try {
            $validated = $this->form->validate($this->form->signingRules());
            $this->validate(['referralStatusReason' => ['required', 'string']]);
            $snapshot = $this->fetchServiceRequestSnapshot($this->uuid, $record->uuid);
            if (Auth::user()->cannot('registeredAction', [ServiceRequestRequest::class, $snapshot, 'recall'])) {
                Session::flash('error', __('referrals.messages.action_unavailable'));

                return;
            }
            $record = MedicalEventsRepository::serviceRequest()->storeResource((new ServiceRequestMapper())->normalize($snapshot), (int) $this->personId, $record->employeeId, $record->divisionId);
            $payload = ServiceRequestForm::buildStatusContent($snapshot, $this->referralStatusReason, $letter, 'eHealth/service_request_recall_reasons');
            $signedContent = signatureService()->signData(
                Arr::toSnakeCase($payload),
                $validated['password'],
                $validated['knedp'],
                $validated['keyContainerUpload'],
                Auth::user()->party->taxId
            );

            // Eligibility and pending operations can change during KEP; reload before creating submission tracking.
            try {
                $record = app(MedicalRequestOwnership::class)->serviceForPerson($record->uuid, (int) $this->personId);
            } catch (ModelNotFoundException) {
                Session::flash('error', __('referrals.messages.action_unavailable'));

                return;
            }
            if (Auth::user()->cannot('recall', $record)
                || Auth::user()->cannot('registeredAction', [ServiceRequestRequest::class, $snapshot, 'recall'])) {
                Session::flash('error', __('referrals.messages.action_unavailable'));

                return;
            }

            $finalResponse = $this->submitClinicalOperation($record, ['status' => 'recalled', 'statusReason' => $payload['status_reason'], 'explanatoryLetter' => $letter], fn () => SubmissionApi::serviceRequest()->recall($this->uuid, $record->uuid, ['signed_data' => $signedContent, 'signed_data_encoding' => 'base64']), 'service_request:recall');
            if ($finalResponse === null) {
                return;
            }

            $this->persistReferralStatusFromJob($finalResponse, $record, ServiceRequestStatus::RECALLED);
            $this->showSignatureModal = false;
            $this->actionType = null;
            $this->requestIdToSign = null;
            $this->referralExplanatoryLetter = '';
            $this->form->resetSigningFields();
            $this->loadReferrals();
            Session::flash('success', __('care-plan.referral_recall_success'));
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (EHealthValidationException $exception) {
            Session::flash('error', $exception->getTranslatedMessage());
            $this->showSignatureModal = false;
        } catch (Throwable $exception) {
            Log::error('PatientReferrals: failed to recall referral: '.$exception->getMessage());
            Session::flash('error', 'Не вдалося відкликати направлення: '.$exception->getMessage());
            $this->showSignatureModal = false;
        }
    }

    public function signCancelReferral(): void
    {
        if (empty($this->requestIdToSign)) {
            Session::flash('error', 'Не вибрано направлення для скасування');
            $this->showSignatureModal = false;

            return;
        }

        $record = $this->ownedReferral((string) $this->requestIdToSign);
        if ($record instanceof DeviceRequestRequest) {
            $this->redirectRoute('persons.device-requests', ['legalEntity' => legalEntity(), 'person' => $this->patient()]);

            return;
        }

        if (Auth::user()->cannot('cancel', $record)) {
            Session::flash('error', __('referrals.messages.action_unavailable'));

            return;
        }

        try {
            $validated = $this->form->validate($this->form->signingRules());
            $this->validate(['referralStatusReason' => ['required', 'string'], 'referralExplanatoryLetter' => ['required', 'string']]);
            $snapshot = $this->fetchServiceRequestSnapshot($this->uuid, $record->uuid);
            if (Auth::user()->cannot('registeredAction', [ServiceRequestRequest::class, $snapshot, 'error'])) {
                Session::flash('error', __('referrals.messages.action_unavailable'));

                return;
            }
            $record = MedicalEventsRepository::serviceRequest()->storeResource((new ServiceRequestMapper())->normalize($snapshot), (int) $this->personId, $record->employeeId, $record->divisionId);
            $payload = ServiceRequestForm::buildStatusContent($snapshot, $this->referralStatusReason, trim($this->referralExplanatoryLetter), 'eHealth/service_request_cancel_reasons');
            $signedContent = signatureService()->signData(
                Arr::toSnakeCase($payload),
                $validated['password'],
                $validated['knedp'],
                $validated['keyContainerUpload'],
                Auth::user()->party->taxId
            );

            // Eligibility and pending operations can change during KEP; reload before creating submission tracking.
            try {
                $record = app(MedicalRequestOwnership::class)->serviceForPerson($record->uuid, (int) $this->personId);
            } catch (ModelNotFoundException) {
                Session::flash('error', __('referrals.messages.action_unavailable'));

                return;
            }
            if (Auth::user()->cannot('cancel', $record)
                || Auth::user()->cannot('registeredAction', [ServiceRequestRequest::class, $snapshot, 'error'])) {
                Session::flash('error', __('referrals.messages.action_unavailable'));

                return;
            }

            $finalResponse = $this->submitClinicalOperation($record, ['status' => 'entered-in-error', 'statusReason' => $payload['status_reason'], 'explanatoryLetter' => trim($this->referralExplanatoryLetter)], fn () => SubmissionApi::serviceRequest()->cancel($this->uuid, $record->uuid, ['signed_data' => $signedContent, 'signed_data_encoding' => 'base64']), 'service_request:cancel');
            if ($finalResponse === null) {
                return;
            }

            $this->persistReferralStatusFromJob($finalResponse, $record, ServiceRequestStatus::ENTERED_IN_ERROR);
            $this->showSignatureModal = false;
            $this->actionType = null;
            $this->requestIdToSign = null;
            $this->form->resetSigningFields();
            $this->loadReferrals();
            Session::flash('success', __('care-plan.referral_cancel_success'));
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (EHealthValidationException $exception) {
            Session::flash('error', $exception->getTranslatedMessage());
            $this->showSignatureModal = false;
        } catch (Throwable $exception) {
            Log::error('PatientReferrals: failed to cancel referral: '.$exception->getMessage());
            Session::flash('error', 'Не вдалося скасувати направлення: '.$exception->getMessage());
            $this->showSignatureModal = false;
        }
    }

    public function signDraft(): void
    {
        if (empty($this->requestIdToSign)) {
            Session::flash('error', 'Не вибрано направлення для підписання');
            $this->showSignatureModal = false;

            return;
        }

        $requestRecord = $this->ownedReferral((string) $this->requestIdToSign);
        if ($requestRecord instanceof DeviceRequestRequest) {
            $this->redirectRoute('persons.device-requests.create', ['legalEntity' => legalEntity(), 'person' => $this->patient(), 'draft' => $requestRecord->uuid]);

            return;
        }

        $author = MedicalEventsRepository::serviceRequest()->authorForDraft($requestRecord->employeeId, $requestRecord->requesterEmployee?->value);
        $category = $requestRecord->category?->coding?->first()?->code ?? '';
        if ($author === null || !in_array($requestRecord->status, ['draft', 'new'], true)
            || Auth::user()->cannot('create', [ServiceRequestRequest::class, $author, $category])) {
            Session::flash('error', __('referrals.messages.action_unavailable'));

            return;
        }

        try {
            $lifecycle = app(ReferralRequestLifecycleService::class);

            $activity = $requestRecord->basedOn?->value
                ? CarePlanActivity::query()->whereUuid($requestRecord->basedOn->value)
                    ->whereHas('carePlan', fn ($query) => $query->wherePersonId($this->personId)->whereLegalEntityId(legalEntity()->id))->first()
                : null;
            $carePlan = $activity !== null
                ? CarePlan::query()->with(['encounter.episode', 'person'])->find($activity->carePlanId)
                : null;
            $encounter = $requestRecord->context?->value
                ? Encounter::forPatient($this->patient())->with('episode')->whereUuid($requestRecord->context->value)->first()
                : $carePlan?->encounter;

            if (filled($requestRecord->basedOn?->value) && $activity === null) {
                throw ValidationException::withMessages(['care_plan_activity_id' => __('referrals.messages.activity_unavailable')]);
            }

            if ($carePlan === null && $encounter === null) {
                throw new RuntimeException('Не знайдено взаємодію або план лікування для направлення');
            }

            // A registry entry cannot bypass the source encounter's current document permissions or inpatient rules.
            if ((filled($requestRecord->context?->value) && $encounter === null)
                || ($encounter !== null && (Auth::user()->cannot('createServiceRequest', [$encounter, $this->patient(), legalEntity()->id])
                    || Auth::user()->cannot('create', [ServiceRequestRequest::class, $author, $category, $encounter])))) {
                Session::flash('error', __('referrals.messages.action_unavailable'));

                return;
            }

            $context = $encounter ?? $carePlan;
            $actingEmployeeId = $requestRecord->employeeId ?? Auth::user()?->activeDoctorEmployee()?->id;
            $employeeContext = $context instanceof Encounter
                ? $lifecycle->resolveEncounterEmployeeContext($context, $actingEmployeeId)
                : $lifecycle->resolveEmployeeContext($carePlan, $activity, $actingEmployeeId);

            $dbData = $lifecycle->buildSignDbData($requestRecord, $activity, $context, $employeeContext);
            if ($activity !== null && $encounter !== null) {
                // Reopening from the patient registry uses the same current approval and quantity guard as the encounter form.
                $dbData = MedicalEventsRepository::serviceRequest()->encounterActivityContext($encounter, $dbData, $employeeContext['employee_uuid']);
            }
            $validated = $this->form->validate($this->form->signingRules());

            $uuids = [
                'person_uuid' => $this->uuid,
                'encounter_uuid' => $encounter?->uuid,
                'episode_uuid' => $encounter?->episode?->value ?? null,
                'employee_uuid' => $employeeContext['employee_uuid'],
                'legal_entity_uuid' => $employeeContext['legal_entity_uuid'],
            ];

            $mapper = new ServiceRequestMapper();
            $signPayload = $mapper->toCreateSignedContent(
                $dbData,
                $uuids,
                $carePlan !== null ? (string) $carePlan->uuid : null,
                $activity !== null ? (string) $activity->uuid : null
            );

            $signedContent = signatureService()->signData(
                $signPayload,
                $validated['password'],
                $validated['knedp'],
                $validated['keyContainerUpload'],
                Auth::user()->party->taxId
            );

            // Signing can outlive source permissions; recheck the saved category and current source before storage.
            $freshEncounter = $encounter?->fresh(['episode', 'class', 'type.coding']);
            $author = MedicalEventsRepository::serviceRequest()->authorForDraft($employeeContext['employee_id'], $employeeContext['employee_uuid']);
            if ($author === null || ($encounter !== null && $freshEncounter === null)
                || ($freshEncounter !== null && Auth::user()->cannot('createServiceRequest', [$freshEncounter, $this->patient(), legalEntity()->id]))
                || Auth::user()->cannot('create', [ServiceRequestRequest::class, $author, (string) ($dbData['category'] ?? ''), $freshEncounter])) {
                Session::flash('error', __('referrals.messages.action_unavailable'));

                return;
            }

            if ($activity !== null && $encounter !== null
                && MedicalEventsRepository::serviceRequest()->encounterActivityContext($encounter, $dbData, $employeeContext['employee_uuid']) !== $dbData) {
                throw ValidationException::withMessages(['care_plan_activity_id' => __('referrals.messages.activity_unavailable')]);
            }

            $resource = $mapper->toFhir($dbData, $uuids + ['carePlanUuid' => $carePlan?->uuid]);
            $requestRecord = MedicalEventsRepository::serviceRequest()->storeResource($resource, (int) $this->personId, $requestRecord->employeeId, $requestRecord->divisionId);
            $finalResponse = $this->submitClinicalOperation($requestRecord, ['status' => 'active'], fn () => SubmissionApi::serviceRequest()->createSigned($this->uuid, ['signed_data' => $signedContent, 'signed_data_encoding' => 'base64']));
            if ($finalResponse === null) {
                return;
            }

            $dbData = $lifecycle->persistAfterSignedCreate(
                $dbData,
                $finalResponse,
                'service_request',
                (int) $this->personId
            );

            if ($activity !== null && $activity->status === 'scheduled') {
                $activity->update(['status' => 'in-progress']);
            }

            $this->showSignatureModal = false;
            $this->actionType = null;
            $this->requestIdToSign = null;
            $this->form->resetSigningFields();
            $this->loadReferrals();
            Session::flash(
                'success',
                'Електронне направлення успішно підписано (№ '.($dbData['request_number'] ?? $dbData['uuid']).').'
            );
        } catch (ValidationException $exception) {
            if (array_intersect(['care_plan_activity_id', 'encounterReferralForm.care_plan_activity_id', 'encounterReferralForm.quantity'], array_keys($exception->errors())) !== []) {
                Session::flash('error', $exception->validator->errors()->first());
            }
            throw $exception;
        } catch (EHealthValidationException $exception) {
            $exception->report();
            Session::flash('error', $exception->getFormattedMessage());
            $this->showSignatureModal = false;
        } catch (Throwable $exception) {
            Log::error('PatientReferrals: failed to sign referral: '.$exception->getMessage());
            Session::flash('error', 'Не вдалося підписати направлення: '.$exception->getMessage());
            $this->showSignatureModal = false;
        }
    }

    public function resendSms(string $uuid, string $kind): void
    {
        $record = $this->ownedReferral($uuid);
        if ($record instanceof DeviceRequestRequest) {
            $this->redirectRoute('persons.device-requests', ['legalEntity' => legalEntity(), 'person' => $this->patient()]);

            return;
        }

        try {
            $response = app(ReferralRequestLifecycleService::class)->resendSms($this->uuid, $uuid, $kind);

            if ($response->successful()) {
                Session::flash('success', __('care-plan.referral_sms_resent'));

                return;
            }

            Session::flash('error', __('referrals.sms_failed'));
        } catch (EHealthValidationException $exception) {
            Session::flash('error', $exception->getTranslatedMessage());
        } catch (EHealthResponseException $exception) {
            if ($exception->response->status() === 403) {
                Session::flash('warning', __('care-plan.referral_sms_forbidden'));

                return;
            }

            Session::flash('error', 'Помилка надсилання СМС: '.$exception->getMessage());
        } catch (Throwable $exception) {
            Log::error('PatientReferrals: failed to resend SMS: '.$exception->getMessage());
            Session::flash('error', 'Помилка надсилання СМС: '.$exception->getMessage());
        }
    }

    public function loadReferralPrintoutForm(string $uuid): string
    {
        $this->registryPrintUuid = null;
        $record = $this->ownedReferral($uuid);
        if ($record instanceof ServiceRequestRequest && Auth::user()->cannot('viewRecord', $record)) {
            Session::flash('error', __('referrals.messages.action_unavailable'));

            return '';
        }

        try {
            return app(ReferralRequestLifecycleService::class)->buildPrintoutHtml($record, $uuid);
        } catch (Throwable $exception) {
            Log::error('PatientReferrals: failed to load printout: '.$exception->getMessage());
            Session::flash('error', 'Не вдалося завантажити друковану форму.');

            return '';
        }
    }

    public function render(): View
    {
        return view('livewire.person.records.referrals');
    }

    /**
     * @param  array<string, mixed>  $finalResponse
     */
    private function persistReferralStatusFromJob(
        array $finalResponse,
        ServiceRequestRequest|DeviceRequestRequest $record,
        ServiceRequestStatus $fallback
    ): void {
        $result = $finalResponse['result'] ?? null;
        $entity = is_array($result) ? ($result[0] ?? $result) : $finalResponse;
        $raw = is_array($entity) ? ($entity['status'] ?? null) : null;
        $resolved = is_string($raw) ? ServiceRequestStatus::resolve($raw) : null;

        $record->update([
            'status' => ($resolved ?? $fallback)->value,
        ]);
    }

    private function ownedReferral(string $uuid): ServiceRequestRequest|DeviceRequestRequest
    {
        abort_unless($this->personId !== null, 404);

        return app(MedicalRequestOwnership::class)
            ->referralForPerson($uuid, $this->personId);
    }

    protected function loadReferralStatusReasons(string $operation): void
    {
        $this->referralStatusReason = '';
        $this->referralExplanatoryLetter = '';
        try {
            $this->referralStatusReasons = dictionary()->basics()->byName('eHealth/service_request_'.$operation.'_reasons')->asCodeDescription()->all();
        } catch (Throwable $exception) {
            $this->referralStatusReasons = [];
            Log::warning('Referral status reasons could not be loaded', ['exceptionType' => $exception::class]);
            Session::flash('error', __('Не вдалося завантажити довідник причин зміни направлення.'));
        }
    }
}
