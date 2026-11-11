<?php

declare(strict_types=1);

namespace App\Livewire\CarePlan\Concerns;

use App\Classes\eHealth\Api\Approval as ApprovalApi;

use App\Classes\eHealth\Api\CarePlan as CarePlanApi;
use App\Classes\eHealth\EHealth;
use App\Enums\CarePlanStatus;
use App\Exceptions\EHealth\EHealthConnectionException;
use App\Exceptions\EHealth\EHealthJobTimeoutException;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Exceptions\EHealth\EHealthValidationException;
use App\Jobs\CarePlanFullSync;
use App\Livewire\CarePlan\Forms\CarePlanActivityForm;
use App\Livewire\CarePlan\Forms\CarePlanForm;
use App\Livewire\Concerns\ReadsClinicalSnapshots;
use App\Livewire\Concerns\SubmitsClinicalOperations;
use App\Models\CarePlan;
use App\Models\LegalEntity;
use App\Repositories\CarePlanActivityRepository;
use App\Repositories\CarePlanRepository;
use App\Repositories\Repository;
use App\Services\MedicalEvents\CarePlanApprovalService;
use App\Services\MedicalEvents\CarePlanLifecycleGateService;
use App\Services\MedicalEvents\FhirResource;
use App\Services\MedicalEvents\MedicalRequestOwnership;
use App\Services\MedicalEvents\PatientApprovalService;
use App\Traits\SynchronizesCarePlans;
use Exception;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use RuntimeException;
use Throwable;

trait CarePlanManager
{
    use HandlesCarePlanApprovalSms;

    use ReadsClinicalSnapshots;

    use SubmitsClinicalOperations;
    use SynchronizesCarePlans;

    #[Locked]
    public bool $activateAfterSubmission = false;

    public bool $showActivationPrompt = false;

    protected function continuePlanActivation(): void
    {
        if (!$this->activateAfterSubmission || !$this->canRequestPatientApproval || Session::get($this->activationPromptSessionKey(), false)) {
            return;
        }
        $this->activateAfterSubmission = false;
        $this->showActivationPrompt = true;
    }

    public function acceptPlanActivation(): void
    {
        $this->authorizeCarePlanWrite();
        Session::put($this->activationPromptSessionKey(), true);
        $this->showActivationPrompt = false;
        $this->openMethodSelectionModal();
    }

    public function dismissPlanActivation(): void
    {
        Session::put($this->activationPromptSessionKey(), true);
        $this->showActivationPrompt = false;
    }

    protected function activationPromptSessionKey(): string
    {
        return 'care-plan.activation-prompt.'.$this->carePlan->id.'.'.($this->planSubmission['id'] ?? 'imported');
    }

    public function refreshDisplayedDetails(): void
    {
        $this->remotePlanDetails = [];

        if (!Auth::user()->can('view', $this->carePlan)) {
            return;
        }

        if (!$this->carePlan->uuid || in_array(CarePlanStatus::fromStored($this->carePlan->status), [CarePlanStatus::PENDING, CarePlanStatus::DRAFT], true)) {
            return;
        }

        try {
            $this->remotePlanDetails = Repository::carePlan()->displayedDetails($this->carePlan, $this->dictionaries ?? []);
        } catch (Throwable $exception) {
            report($exception);
            Session::flash('error', __('device-requests.messages.plan_details_unavailable'));
        }
    }

    public function sign(CarePlanRepository $repository, CarePlanActivityRepository $activityRepository): void
    {
        $this->authorizeCarePlanWrite();
        if (in_array($this->actionType, ['cancel', 'complete'], true) && $this->guardPlanLifecycleAccess($this->actionType)) {
            return;
        }
        try {
            $validated = $this->validate($this->rulesForSigning());
        } catch (ValidationException $exception) {
            Session::flash('error', $exception->validator->errors()->first());
            $this->setErrorBag($exception->validator->getMessageBag());

            return;
        }

        if ($this->actionType === 'sign_activity') {
            $this->signActivity($repository, $activityRepository);

            return;
        }

        if ($this->actionType === 'complete') {
            $this->completePlan($repository);

            return;
        }

        if ($this->actionType === 'sign_eprescription') {
            if (!method_exists($this, 'signEPrescription')) {
                Session::flash('error', __('care-plan.unexpected_error'));
                $this->showSignatureModal = false;

                return;
            }

            $this->signEPrescription();

            return;
        }

        if ($this->actionType === 'sign_servicerequest' || $this->actionType === 'sign_devicerequest') {
            if (!method_exists($this, 'signReferral')) {
                Session::flash('error', __('care-plan.unexpected_error'));
                $this->showSignatureModal = false;

                return;
            }

            $this->signReferral();

            return;
        }

        if (in_array($this->actionType, ['complete_activity', 'cancel_activity'])) {
            $this->signStatusActivity($activityRepository);

            return;
        }

        if ($this->actionType === 'cancel_prescription' || $this->actionType === 'reject_prescription') {
            if (!method_exists($this, 'signRejectPrescription')) {
                Session::flash('error', __('care-plan.unexpected_error'));
                $this->showSignatureModal = false;

                return;
            }

            // eHealth medication-request flow in this app uses reject (no separate cancel signer).
            $this->signRejectPrescription();

            return;
        }

        if ($this->actionType === 'cancel_referral') {
            if (!method_exists($this, 'signCancelReferral')) {
                Session::flash('error', __('care-plan.unexpected_error'));
                $this->showSignatureModal = false;

                return;
            }

            $this->signCancelReferral();

            return;
        }

        if ($this->actionType === 'recall_referral') {
            if (!method_exists($this, 'signRecallReferral')) {
                Session::flash('error', __('care-plan.unexpected_error'));
                $this->showSignatureModal = false;

                return;
            }

            $this->signRecallReferral();

            return;
        }

        if ($this->actionType === 'sign_plan') {
            if ($this->carePlan->status !== CarePlanStatus::DRAFT->value) {
                $this->showSignatureModal = false;

                return;
            }
            $this->signPlan($repository);

            return;
        }

        if (empty($this->carePlan->uuid) || $this->carePlan->status === CarePlanStatus::DRAFT->value) {
            Session::flash('error', __('care-plan.care_plan_not_synced'));
            $this->showSignatureModal = false;

            return;
        }

        if ($this->actionType !== 'cancel') {
            $this->showSignatureModal = false;

            return;
        }

        if ($this->actionType === 'cancel') {
            $planCancelBlock = app(CarePlanLifecycleGateService::class)
                ->planCancelBlockReason($this->carePlan);

            if ($planCancelBlock !== null) {
                Session::flash('error', $planCancelBlock);
                $this->showSignatureModal = false;

                return;
            }
        }

        $systemMap = [
            'cancel' => 'eHealth/care_plan_cancel_reasons',
            'complete' => 'eHealth/care_plan_complete_reasons',
        ];

        $statusReasonCodeableConcept = [
            'coding' => [
                [
                    'system' => $systemMap[$this->actionType] ?? 'eHealth/care_plan_cancel_reasons',
                    'code' => $this->statusReason,
                ]
            ]
        ];

        try {
            $payloadForSign = $this->buildCarePlanStatusChangePayload($statusReasonCodeableConcept);

            Log::info('CarePlanShow: signing care-plan status change', [
                'actionType' => $this->actionType,
                'carePlanId' => $this->carePlan->id,
            ]);

            $signedContent = signatureService()->signData(
                $payloadForSign,
                $this->form['password'],
                $this->form['knedp'],
                $this->form['keyContainerUpload'],
                Auth::user()->party->taxId
            );

            $finalResponse = $this->submitClinicalOperation($this->carePlan, ['status' => CarePlanStatus::CANCELLED->value, 'statusReason' => $statusReasonCodeableConcept], fn () => app(CarePlanApi::class)->cancel(
                $this->carePlan->person->uuid,
                $this->carePlan->uuid,
                [
                    'signed_data' => $signedContent,
                    'signed_data_encoding' => 'base64',
                ]
            ));
            if ($finalResponse === null) {
                return;
            }

            $this->refreshCarePlan();

            session()->flash('success', __('care-plan.care_plan_cancelled'));
            $this->showSignatureModal = false;
            $this->redirectAfterCarePlanClosed();

        } catch (EHealthJobTimeoutException $exception) {
            $exception->report();
            Session::flash('error', $exception->getMessage());
            $this->showSignatureModal = false;
        } catch (EHealthConnectionException $exception) {
            Log::error('CarePlanShow: connection error: ' . $exception->getMessage());
            Session::flash('error', __('care-plan.connection_error'));
            $this->showSignatureModal = false;
        } catch (EHealthValidationException|EHealthResponseException $exception) {
            if (method_exists($exception, 'report')) {
                $exception->report();
            }
            Log::error('CarePlanShow: eHealth error: ' . $exception->getMessage(), [
                'details' => method_exists($exception, 'getDetails') ? $exception->getDetails() : null
            ]);
            $msg = $exception instanceof EHealthValidationException
                ? $exception->getFormattedMessage()
                : __('care-plan.ehealth_error_prefix') . $exception->getMessage();

            if ($this->actionType === 'cancel' && $this->isCarePlanAlreadyCancelledError($exception)) {
                $repository->updateById($this->carePlan->id, [
                    'status' => CarePlanStatus::CANCELLED->value,
                ]);
                $this->refreshCarePlan();
            }

            Session::flash('error', $msg);
            $this->showSignatureModal = false;
        } catch (Throwable $exception) {
            Log::error('CarePlanShow: unexpected error: ' . $exception->getMessage(), [
                'exception' => $exception,
                'actionType' => $this->actionType,
            ]);
            Session::flash(
                'error',
                __('care-plan.unexpected_error') . ' ' . $exception->getMessage()
            );
            $this->showSignatureModal = false;
        }
    }

    protected function signPlan(CarePlanRepository $repository): void
    {
        try {
            $repository->assertDraftSigningReady($this->carePlan);
            $resource = $repository->getResource($this->carePlan->id);
            $payload = CarePlanForm::buildCreateContent($resource);
            $signedContent = signatureService()->signData($payload, $this->form['password'], $this->form['knedp'], $this->form['keyContainerUpload'], Auth::user()->party->taxId);
            $result = $this->submitClinicalOperation($this->carePlan, ['status' => CarePlanStatus::PENDING->value], fn () => EHealth::carePlan()->create($this->carePlan->person->uuid, ['signed_data' => $signedContent, 'signed_data_encoding' => 'base64']));
            if ($result === null) {
                $this->activateAfterSubmission = true;
                $this->planSubmission = $repository->submissionState($this->carePlan);

                return;
            }
            $this->refreshCarePlan();
            Session::flash('success', __('care-plan.signed_and_sent'));
            $this->showSignatureModal = false;
            $this->activateAfterSubmission = true;
            $this->continuePlanActivation();
        } catch (Throwable $exception) {
            report($exception);
            Session::flash('error', match (true) {
                $exception instanceof ValidationException => $exception->validator->errors()->first(),
                $exception instanceof EHealthValidationException => $exception->getFormattedMessage(),
                default => __('care-plan.unexpected_error'),
            });
            $this->planSubmission = $repository->submissionState($this->carePlan);
            if ($exception instanceof ValidationException) {
                $this->setErrorBag($exception->validator->getMessageBag());
            } else {
                $this->showSignatureModal = false;
            }
        }
    }

    public function completePlan(CarePlanRepository $repository): void
    {
        $this->authorizeCarePlanWrite();
        if ($this->guardPlanLifecycleAccess('complete')) {
            return;
        }
        $this->validate([
            'statusReason' => 'required|string'
        ]);

        if (empty($this->carePlan->uuid)) {
            Session::flash('error', __('care-plan.care_plan_not_synced'));
            $this->showSignatureModal = false;

            return;
        }

        $blockReason = app(CarePlanLifecycleGateService::class)
            ->planCompleteBlockReason($this->carePlan);

        if ($blockReason !== null) {
            Session::flash('error', $blockReason);
            $this->showSignatureModal = false;

            return;
        }

        $statusReasonCodeableConcept = [
            'coding' => [
                [
                    'system' => 'eHealth/care_plan_complete_reasons',
                    'code' => $this->statusReason,
                ]
            ]
        ];

        try {
            $finalResponse = $this->submitClinicalOperation($this->carePlan, ['status' => CarePlanStatus::COMPLETED->value, 'statusReason' => $statusReasonCodeableConcept], fn () => app(CarePlanApi::class)->complete(
                $this->carePlan->person->uuid,
                $this->carePlan->uuid,
                [
                    'status_reason' => $statusReasonCodeableConcept,
                ]
            ));
            if ($finalResponse === null) {
                return;
            }

            $this->refreshCarePlan();

            session()->flash('success', __('care-plan.care_plan_completed'));
            $this->showSignatureModal = false;
            $this->redirectAfterCarePlanClosed();

        } catch (EHealthJobTimeoutException $exception) {
            $exception->report();
            Session::flash('error', $exception->getMessage());
            $this->showSignatureModal = false;
        } catch (EHealthConnectionException $exception) {
            Log::error('CarePlanShow: connection error: ' . $exception->getMessage());
            Session::flash('error', __('care-plan.connection_error'));
        } catch (EHealthValidationException|EHealthResponseException $exception) {
            if (method_exists($exception, 'report')) {
                $exception->report();
            }
            Log::error('CarePlanShow: eHealth error: ' . $exception->getMessage(), [
                'details' => method_exists($exception, 'getDetails') ? $exception->getDetails() : null
            ]);
            $msg = $exception instanceof EHealthValidationException
                ? $exception->getFormattedMessage()
                : __('care-plan.ehealth_error_prefix') . $exception->getMessage();
            Session::flash('error', $msg);
        } catch (Throwable $exception) {
            Log::error('CarePlanShow: unexpected error: ' . $exception->getMessage());
            Session::flash('error', __('care-plan.unexpected_error'));
        }
    }

    protected function signActivity(CarePlanRepository $repository, CarePlanActivityRepository $activityRepository): void
    {
        if (!$this->authorizeCarePlanActivityWrite()) {
            return;
        }
        $keepModalOpen = false;
        try {
            $activity = $this->ownedActivity((int) $this->activityToSign);
            if ($activity->status !== CarePlanStatus::DRAFT->value) {
                throw ValidationException::withMessages(['activityToSign' => __('care-plan.activity_signed')]);
            }
            if (method_exists($this, 'getDeviceSignReadinessWarning')) {
                $warning = $this->getDeviceSignReadinessWarning($activity);
                if ($warning !== null) {
                    throw ValidationException::withMessages(['activityToSign' => $warning]);
                }
            }
            $resource = $activityRepository->getResource($activity->id);
            $this->assertMedicationActivityProgramForSigning($resource);
            $this->assertDeviceActivityQuantityForSigning($resource);
            $payload = CarePlanActivityForm::buildCreateContent($resource);
            $signedContent = signatureService()->signData($payload, $this->form['password'], $this->form['knedp'], $this->form['keyContainerUpload'], Auth::user()->party->taxId);
            $result = $this->submitClinicalOperation($activity, ['status' => 'scheduled'], fn () => EHealth::carePlanActivity()->create($this->carePlan->person->uuid, $this->carePlan->uuid, ['signed_data' => $signedContent, 'signed_data_encoding' => 'base64']));
            if ($result === null) {
                $this->redirectRoute('care-plans.activities.show', [legalEntity(), $this->carePlan->id, $activity->id], navigate: true);

                return;
            }
            $this->syncPlanStatus();
            $this->refreshCarePlan();
            Session::flash('success', __('care-plan.activity_signed'));
        } catch (Throwable $exception) {
            if ($exception instanceof ValidationException) {
                $keepModalOpen = true;
                $this->setErrorBag($exception->validator->errors());
            }
            report($exception);
            Session::flash('error', $exception instanceof EHealthValidationException ? $exception->getFormattedMessage() : ($exception instanceof ValidationException ? $exception->validator->errors()->first() : __('care-plan.unexpected_error')));
        } finally {
            if (!$keepModalOpen) {
                $this->showSignatureModal = false;
            }
        }
    }

    protected function signStatusActivity(CarePlanActivityRepository $activityRepository): void
    {
        if (!$this->authorizeCarePlanActivityWrite()) {
            return;
        }
        if (!$this->activityToSign) {
            Session::flash('error', __('care-plan.no_activity_selected'));

            return;
        }
        $activity = $this->ownedActivity((int) $this->activityToSign);
        $blockReason = app(CarePlanLifecycleGateService::class)->activityStatusChangeBlockReason($activity, $this->actionType);
        if ($blockReason !== null) {
            Session::flash('error', $blockReason);

            return;
        }
        try {
            $complete = $this->actionType === 'complete_activity';
            $reason = FhirResource::make()->coding($complete ? 'eHealth/care_plan_activity_complete_reasons' : 'eHealth/care_plan_activity_cancel_reasons', $this->statusReason)->toCodeableConcept();
            $api = EHealth::carePlanActivity();
            $outcomes = [];
            if ($complete) {
                $this->validate(['outcomeCode' => ['required', 'string']]);
                if ($this->outcomeReferences !== []) {
                    $this->addError('outcomeReferences', __('Для завершення оберіть результат із довідника. Посилання на записи не передаються цією операцією.'));

                    return;
                }
                $outcomes = [FhirResource::make()->coding('eHealth/care_plan_activity_outcomes', $this->outcomeCode)->toCodeableConcept()];
                $payload = CarePlanActivityForm::buildCompleteContent($reason, $outcomes);
                $result = $this->submitClinicalOperation($activity, ['status' => 'completed', 'statusReason' => $reason, 'outcomeCodeableConcept' => $outcomes], fn () => $api->complete($this->carePlan->person->uuid, $this->carePlan->uuid, $activity->uuid, $payload));
            } else {
                $snapshot = $this->fetchActivityCancelSnapshot($this->carePlan->person->uuid, $this->carePlan->uuid, $activity->uuid);
                $content = CarePlanActivityForm::buildCancelContent($snapshot, $reason);
                $signed = signatureService()->signData($content, $this->form['password'], $this->form['knedp'], $this->form['keyContainerUpload'], Auth::user()->party->taxId);
                $result = $this->submitClinicalOperation($activity, ['status' => 'cancelled', 'statusReason' => $reason], fn () => $api->cancel($this->carePlan->person->uuid, $this->carePlan->uuid, $activity->uuid, ['signed_data' => $signed, 'signed_data_encoding' => 'base64']));
            }
            if ($result === null) {
                return;
            }
            $this->refreshCarePlan();
            Session::flash('success', __($complete ? 'care-plan.activity_completed' : 'care-plan.activity_cancelled'));
            $this->showSignatureModal = false;
        } catch (ValidationException $exception) {
            $this->setErrorBag($exception->validator->getMessageBag());
        } catch (Throwable $exception) {
            report($exception);
            Session::flash('error', $exception instanceof EHealthValidationException ? $exception->getFormattedMessage() : __('care-plan.unexpected_error'));
            $this->showSignatureModal = false;
        }
    }

    public function openMethodSelectionModal(): void
    {
        if (!$this->guardApprovalAccess($this->carePlan)) {
            return;
        }
        if ($this->guardTerminalCarePlanMutation()) {
            return;
        }

        try {
            Repository::carePlan()->assertApprovalCreationReady($this->carePlan);
        } catch (ValidationException $exception) {
            Session::flash('error', $exception->validator->errors()->first());

            return;
        }

        if (empty($this->carePlan->uuid)) {
            Session::flash('error', __('План лікування ще не синхронізовано з ЕСОЗ.'));

            return;
        }

        $service = app(CarePlanApprovalService::class);
        if ($service->skipsPatientOtp($this->carePlan)) {
            $submission = Repository::carePlan()->latestSubmissionLink($this->carePlan);
            if ($submission === null) {
                $this->createApproval('');

                return;
            }
            try {
                $employeeUuid = $this->currentCarePlanEmployee()?->uuid;
                $bearerToken = session()->get(config('ehealth.api.oauth.bearer_token'));
                if (!$employeeUuid || !$bearerToken) {
                    throw new RuntimeException(__('care-plan.approval_create_error'));
                }
                Repository::carePlan()->queueInpatientApproval($submission, legalEntity(), Auth::user(), $bearerToken, $employeeUuid);
                $this->inpatientApprovalStatus = 'queued';
                Session::flash('info', __('care-plan.inpatient_access_processing'));
            } catch (Throwable $exception) {
                report($exception);
                Session::flash('error', __('care-plan.inpatient_access_failed'));
            }

            return;
        }

        try {
            $methods = EHealth::person()->getAuthMethods($this->carePlan->person->uuid)->validate();
            $this->authMethods = app(PatientApprovalService::class)
                ->enrichAuthenticationMethods($methods, $this->carePlan->person);
            $this->showMethodSelectionModal = true;
        } catch (Exception $e) {
            Log::error('CarePlanShow: failed to load auth methods: ' . $e->getMessage());
            Session::flash('error', __('Не вдалося завантажити методи аутентифікації'));
        }
    }

    protected function isCarePlanAlreadyCancelledError(Throwable $exception): bool
    {
        if ($exception instanceof EHealthValidationException) {
            return $exception->isCarePlanAlreadyCancelled();
        }

        $message = strtolower($exception->getMessage());

        return str_contains($message, 'cannot be cancelled')
            && str_contains($message, 'cancelled');
    }

    protected function redirectAfterCarePlanClosed(): void
    {
        $this->carePlan = Repository::carePlan()->findById($this->carePlan->id);

        $personId = $this->carePlan->personId;
        $encounterId = $this->carePlan->encounterId;

        if ($personId && $encounterId) {
            $this->redirectRoute('encounter.view', [legalEntity(), $personId, $encounterId], navigate: true);

            return;
        }

        if ($personId) {
            $this->redirectRoute('persons.care-plans', [legalEntity(), $personId], navigate: true);
        }
    }

    public function selectAuthMethod(string $methodUuid): void
    {
        if (!$this->guardApprovalAccess($this->carePlan)) {
            return;
        }
        $this->currentAuthMethod = $this->selectedApprovalAuthMethod($methodUuid);
        $this->showMethodSelectionModal = false;
        $this->createApproval($methodUuid);
    }

    protected function createApproval(string $methodUuid): void
    {
        try {
            $employeeUuid = $this->currentCarePlanEmployee()?->uuid;

            if (!$employeeUuid) {
                Session::flash('error', __('Не вдалося визначити лікаря для створення дозволу.'));

                return;
            }

            $service = app(CarePlanApprovalService::class);
            $result = $service->create(
                carePlan: $this->carePlan,
                patientUuid: $this->carePlan->person->uuid,
                employeeUuid: $employeeUuid,
                accessLevel: 'write',
                authorizeWith: $service->skipsPatientOtp($this->carePlan) ? null : ($methodUuid ?: null),
                user: Auth::user(),
                bearerToken: session()->get(config('ehealth.api.oauth.bearer_token')),
            );

            if ($result->isAsync()) {
                $this->approvalId = $result->approvalId;
                $this->pollingLinkId = $result->pollingLinkId;
                $this->isPolling = true;
                Session::flash('info', __('care-plan.approval_access_processing'));

                return;
            }

            $this->approvalId = $result->approvalId;

            if ($result->requiresConfirmation()) {
                $this->currentAuthMethod = $result->authMethod ?? $this->currentAuthMethod;
                $this->openAuthModal();

                return;
            }

            $this->refreshCarePlan();
            $this->dispatch('refreshApprovals');
            Session::flash(
                'success',
                $service->skipsPatientOtp($this->carePlan)
                    ? __('care-plan.approval_inpatient_granted')
                    : __('План лікування успішно активовано.')
            );
        } catch (Exception $e) {
            Log::error('CarePlanShow: failed to create approval: ' . $e->getMessage());
            Session::flash('error', __('Не вдалося створити запит на дозвіл: ') . $e->getMessage());
        }
    }

    public function checkApprovalJobStatus(): void
    {
        if (!$this->isPolling || !$this->pollingLinkId) {
            return;
        }

        $status = app(CarePlanApprovalService::class)->resolveAsyncJob($this->pollingLinkId);

        if ($status->isPending()) {
            return;
        }

        $this->isPolling = false;
        $this->pollingLinkId = null;

        if ($status->isFailed()) {
            Session::flash('error', $status->errorMessage ?: __('Не вдалося обробити запит на дозвіл.'));

            return;
        }

        if ($status->approvalId) {
            $this->approvalId = $status->approvalId;
        }

        $service = app(CarePlanApprovalService::class);
        if ($status->requiresConfirmation()) {
            $this->currentAuthMethod = $status->authMethod ?? $this->currentAuthMethod;
            $this->openAuthModal();

            return;
        }

        $this->refreshCarePlan();
        $this->dispatch('refreshApprovals');
        Session::flash(
            'success',
            $service->skipsPatientOtp($this->carePlan)
                ? __('care-plan.approval_inpatient_granted')
                : __('План лікування успішно активовано.')
        );
    }

    public function verify(): void
    {
        if ($this->isSmsResending) {
            Session::flash('info', __('care-plan.sms_resend_processing'));

            return;
        }
        if (!$this->guardApprovalAccess($this->carePlan)) {
            return;
        }
        app(MedicalRequestOwnership::class)->approvalForCarePlan($this->carePlan, $this->approvalId ?? '');
        try {
            $this->validate($this->approvalVerificationRules(), $this->approvalVerificationMessages());
        } catch (ValidationException $exception) {
            Session::flash('error', $exception->validator->errors()->first());
            $this->setErrorBag($exception->validator->getMessageBag());

            return;
        }

        try {
            app(CarePlanApprovalService::class)->verify(
                $this->carePlan->person->uuid,
                $this->approvalId,
                $this->isOfflineAuthMethod() ? null : (int) $this->verificationCode,
            );
        } catch (Exception $e) {
            Log::warning('Care plan approval confirmation failed', ['exceptionType' => $e::class, 'statusCode' => $e->getCode()]);
            $message = $e instanceof EHealthValidationException ? $e->getFormattedMessage()
                : ($e instanceof EHealthResponseException && ApprovalApi::errorMessage($e->response) === 'Invalid verification code'
                    ? __('errors.ehealth.messages.invalid_verification_code') : __('care-plan.approval_verify_error'));
            $this->addError('verificationCode', $message);
            Session::flash('error', $message);

            return;
        }
        $this->resetValidation('verificationCode');
        $this->closeAuthModal();
        try {
            $this->refreshCarePlan();
            $this->dispatch('refreshApprovals');
        } catch (Throwable $exception) {
            Log::warning('Verified care plan approval view could not be refreshed', ['exceptionType' => $exception::class]);
            Session::flash('warning', __('care-plan.approval_verified_refresh_pending'));

            return;
        }
        Session::flash('success', __('care-plan.approval_verified'));
    }

    protected function carePlanForSms(): CarePlan
    {
        return $this->carePlan;
    }

    protected function onSmsApprovalAlreadyGranted(): void
    {
        $this->refreshCarePlan();
        $this->dispatch('refreshApprovals');
    }

    #[Locked]
    public ?string $planSyncBatchId = null;

    public function sync(): void
    {
        if (Auth::user()->cannot('view', $this->carePlan)) {
            Session::flash('error', __('care-plan.sync_access_denied'));

            return;
        }
        if (empty($this->carePlan->uuid)) {
            Session::flash('error', __('care-plan.care_plan_not_synced'));

            return;
        }

        if ($this->planSyncBatchId !== null) {
            $this->checkPlanSyncStatus();

            return;
        }
        $token = session()->get(config('ehealth.api.oauth.bearer_token'));
        if (!is_string($token) || $token === '') {
            Session::flash('error', __('care-plan.sync_session_expired'));

            return;
        }
        $batch = Bus::batch([new CarePlanFullSync(
            legalEntity(),
            standalone: true,
            resourceUuid: $this->carePlan->uuid,
            patientContext: [
                'patient_uuid' => $this->carePlan->person->uuid,
                'person_id' => $this->carePlan->personId,
                'patient_care_plan_registry' => true,
                'care_plan_approvals' => true,
            ],
        )])
            ->withOption('legal_entity_id', legalEntity()->id)
            ->withOption('patient_uuid', $this->carePlan->person->uuid)
            ->withOption('person_id', $this->carePlan->personId)
            ->withOption('patient_care_plan_registry', true)
            ->withOption('care_plan_approvals', true)
            ->withOption('sync_entity', LegalEntity::ENTITY_CARE_PLAN)
            ->withOption('token', Crypt::encryptString($token))
            ->withOption('user', Auth::user())
            ->name(CarePlanFullSync::BATCH_NAME)->onQueue('sync')->dispatch();
        $this->planSyncBatchId = $batch->id;
        Session::flash('info', __('care-plan.sync_processing'));
    }

    public function checkPlanSyncStatus(): void
    {
        if ($this->planSyncBatchId === null) {
            return;
        }
        $batch = Bus::findBatch($this->planSyncBatchId);
        if ($batch !== null && !$batch->finished() && !$batch->cancelled()) {
            return;
        }
        $this->planSyncBatchId = null;
        $this->refreshCarePlan();
        $this->dispatch('refreshApprovals');
        if ($batch !== null && (int) Cache::get('care-plan-sync-denied:'.$batch->id, 0) > 0) {
            Session::flash('warning', __('care-plan.sync_partial_access', [
                'count' => (int) Cache::get('care-plan-sync-denied:'.$batch->id, 0),
            ]));

            return;
        }
        Session::flash(
            $batch !== null && !$batch->hasFailures() && !$batch->cancelled() ? 'success' : 'error',
            __($batch !== null && !$batch->hasFailures() && !$batch->cancelled() ? 'care-plan.data_synced' : 'care-plan.sync_error')
        );
    }

    protected function buildCarePlanStatusChangePayload(array $statusReasonCodeableConcept): array
    {
        return CarePlanForm::buildCancelContent($this->fetchCarePlanCancelSnapshot($this->carePlan->person->uuid, $this->carePlan->uuid), $statusReasonCodeableConcept);
    }

    public function syncPlanStatus(): bool
    {
        try {
            $this->synchronizeResources([['id' => $this->carePlan->uuid]], $this->carePlan->personId, $this->carePlan->legalEntityId, $this->carePlan->person->uuid);
            app(CarePlanApprovalService::class)->syncForCarePlan($this->carePlan);
            $this->refreshCarePlan();
            $this->dispatch('refreshApprovals');

            return true;
        } catch (Throwable $exception) {
            report($exception);

            return false;
        }
    }
}
