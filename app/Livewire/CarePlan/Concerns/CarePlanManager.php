<?php

declare(strict_types=1);

namespace App\Livewire\CarePlan\Concerns;

use App\Classes\eHealth\EHealth;
use App\Enums\CarePlanStatus;
use App\Exceptions\EHealth\EHealthConnectionException;
use App\Exceptions\EHealth\EHealthJobTimeoutException;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Exceptions\EHealth\EHealthValidationException;
use App\Repositories\CarePlanActivityRepository;
use App\Repositories\CarePlanRepository;
use App\Services\MedicalEvents\CarePlanApprovalService;
use App\Services\MedicalEvents\CarePlanLifecycleGateService;
use App\Classes\eHealth\Api\CarePlan as CarePlanApi;
use Exception;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\ValidationException;
use Throwable;

trait CarePlanManager
{
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
            $this->remotePlanDetails = app(CarePlanApi::class)->getRawDetails($this->carePlan->person->uuid, $this->carePlan->uuid);
            foreach ($this->remotePlanDetails['category']['coding'] ?? [] as $index => $coding) {
                if (isset($this->dictionaries['care_plan_categories'][$coding['code']])) {
                    $this->remotePlanDetails['category']['coding'][$index]['display'] = $this->dictionaries['care_plan_categories'][$coding['code']];
                }
            }
        } catch (Throwable $exception) {
            report($exception);
            Session::flash('error', __('device-requests.messages.plan_details_unavailable'));
        }
    }

    public function sign(CarePlanRepository $repository, CarePlanActivityRepository $activityRepository): void
    {
        $this->authorizeCarePlanWrite();
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
                'care_plan_id' => $this->carePlan->id,
            ]);

            $signedContent = signatureService()->signData(
                $payloadForSign,
                $this->form['password'],
                $this->form['knedp'],
                $this->form['keyContainerUpload'],
                Auth::user()->party->taxId
            );

            $finalResponse = app(CarePlanApi::class)->cancelAndResolve(
                $this->carePlan->person->uuid,
                $this->carePlan->uuid,
                [
                    'signed_data' => $signedContent,
                    'signed_data_encoding' => 'base64',
                ]
            );

            $repository->updateById($this->carePlan->id, [
                'status' => CarePlanStatus::fromJobResponse($finalResponse, CarePlanStatus::CANCELLED)->value,
                'statusReason' => $statusReasonCodeableConcept,
            ]);

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
            $resource = $repository->getResource($this->carePlan->id);
            $payload = EHealth::carePlan()->buildCreateContent($resource);
            $signedContent = signatureService()->signData($payload, $this->form['password'], $this->form['knedp'], $this->form['keyContainerUpload'], Auth::user()->party->taxId);
            $result = EHealth::carePlan()->submitSignedCreate($this->carePlan->person->uuid, $signedContent);
            $resource['status'] = CarePlanStatus::fromJobResponse($result, CarePlanStatus::PENDING)->value;
            $resource['requisition'] = data_get($result, 'result.0.requisition') ?? data_get($result, 'result.requisition') ?? $result['requisition'] ?? null;
            $repository->storeResource($resource, $this->carePlan->personId, $this->carePlan->legalEntityId, $this->carePlan->id);
            $this->refreshCarePlan();
            Session::flash('success', __('care-plan.signed_and_sent'));
            $this->showSignatureModal = false;
        } catch (Throwable $exception) {
            report($exception);
            Session::flash('error', $exception instanceof EHealthValidationException ? $exception->getFormattedMessage() : __('care-plan.unexpected_error'));
            $this->showSignatureModal = false;
        }
    }

    public function completePlan(CarePlanRepository $repository): void
    {
        $this->authorizeCarePlanWrite();
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
            $finalResponse = app(CarePlanApi::class)->completeAndResolve(
                $this->carePlan->person->uuid,
                $this->carePlan->uuid,
                [
                    'status_reason' => $statusReasonCodeableConcept,
                ]
            );

            $repository->updateById($this->carePlan->id, [
                'status' => CarePlanStatus::fromJobResponse($finalResponse, CarePlanStatus::COMPLETED)->value,
                'statusReason' => $statusReasonCodeableConcept,
            ]);

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
            $payload = EHealth::carePlanActivity()->buildCreateContent($resource);
            $signedContent = signatureService()->signData($payload, $this->form['password'], $this->form['knedp'], $this->form['keyContainerUpload'], Auth::user()->party->taxId);
            EHealth::carePlanActivity()->submitSignedCreate($this->carePlan->person->uuid, $this->carePlan->uuid, $signedContent);
            // The resolver has confirmed success. Keep the allocated identity and never store a job status.
            $resource['detail']['status'] = 'scheduled';
            $activityRepository->storeResource($resource, $this->carePlan, $activity->id);
            $this->syncPlanStatus();
            $this->refreshCarePlan();
            Session::flash('success', __('care-plan.activity_signed'));
        } catch (Throwable $exception) {
            report($exception);
            Session::flash('error', $exception instanceof EHealthValidationException ? $exception->getFormattedMessage() : ($exception instanceof ValidationException ? $exception->validator->errors()->first() : __('care-plan.unexpected_error')));
        } finally {
            $this->showSignatureModal = false;
        }
    }

    protected function signStatusActivity(CarePlanActivityRepository $activityRepository): void
    {
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
            $reason = \App\Services\MedicalEvents\FhirResource::make()->coding($complete ? 'eHealth/care_plan_activity_complete_reasons' : 'eHealth/care_plan_activity_cancel_reasons', $this->statusReason)->toCodeableConcept();
            $api = EHealth::carePlanActivity();
            $outcomes = [];
            if ($complete) {
                $this->validate(['outcomeCode' => ['required', 'string']]);
                if ($this->outcomeReferences !== []) {
                    $this->addError('outcomeReferences', __('Для завершення оберіть результат із довідника. Посилання на записи не передаються цією операцією.'));

                    return;
                }
                $outcomes = [\App\Services\MedicalEvents\FhirResource::make()->coding('eHealth/care_plan_activity_outcomes', $this->outcomeCode)->toCodeableConcept()];
                $payload = $api->buildCompleteContent($reason, $outcomes);
                $result = $api->completeAndResolve($this->carePlan->person->uuid, $this->carePlan->uuid, $activity->uuid, $payload);
            } else {
                $snapshot = $api->fetchCancelSnapshot($this->carePlan->person->uuid, $this->carePlan->uuid, $activity->uuid);
                $content = $api->buildCancelContent($snapshot, $reason);
                $signed = signatureService()->signData($content, $this->form['password'], $this->form['knedp'], $this->form['keyContainerUpload'], Auth::user()->party->taxId);
                $result = $api->cancelAndResolve($this->carePlan->person->uuid, $this->carePlan->uuid, $activity->uuid, ['signed_data' => $signed, 'signed_data_encoding' => 'base64']);
            }
            $changes = ['status' => CarePlanStatus::fromJobResponse($result, $complete ? CarePlanStatus::COMPLETED : CarePlanStatus::CANCELLED)->value, 'statusReason' => $reason];
            if ($complete) {
                $changes['outcomeCodeableConcept'] = $outcomes;
            }
            $activityRepository->updateById($activity->id, $changes);
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
        if ($this->guardTerminalCarePlanMutation()) {
            return;
        }

        if (empty($this->carePlan->uuid)) {
            Session::flash('error', __('План лікування ще не синхронізовано з ЕСОЗ.'));

            return;
        }

        $service = app(CarePlanApprovalService::class);
        if ($service->skipsPatientOtp($this->carePlan)) {
            $this->createApproval('');

            return;
        }

        try {
            $this->authMethods = EHealth::person()->getAuthMethods($this->carePlan->person->uuid)->getData();
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
        $this->carePlan = app(CarePlanRepository::class)->findById($this->carePlan->id);

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
        $this->currentAuthMethod = collect($this->authMethods)->first(function ($method) use ($methodUuid) {
            return ($method['id'] ?? $method['uuid'] ?? null) === $methodUuid;
        });

        if (is_array($this->currentAuthMethod)) {
            $this->phoneNumber = $this->currentAuthMethod['phone_number']
                ?? $this->currentAuthMethod['phoneNumber']
                ?? null;
        }

        $this->showMethodSelectionModal = false;
        $this->createApproval($methodUuid);
    }

    protected function createApproval(string $methodUuid): void
    {
        try {
            $employeeUuid = Auth::user()?->getCarePlanWriterEmployee($this->carePlan->termsOfService)?->uuid;

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

                return;
            }

            $this->approvalId = $result->approvalId;

            if ($result->requiresOtp() && !$service->skipsPatientOtp($this->carePlan)) {
                $this->currentAuthMethod = $result->authMethod ?? $this->currentAuthMethod;
                $this->openAuthModal();

                return;
            }

            $this->syncPlanStatus();
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
        if ($status->requiresOtp() && !$service->skipsPatientOtp($this->carePlan)) {
            $this->currentAuthMethod = $status->authMethod ?? $this->currentAuthMethod;
            $this->openAuthModal();

            return;
        }

        $this->syncPlanStatus();
        Session::flash(
            'success',
            $service->skipsPatientOtp($this->carePlan)
                ? __('care-plan.approval_inpatient_granted')
                : __('План лікування успішно активовано.')
        );
    }

    public function verify(): void
    {
        $this->validate($this->approvalVerificationRules());

        if ($this->isOfflineAuthMethod()) {
            Log::info('CarePlanManager: offline document verification confirmed for approval ID: ' . $this->approvalId);
            $this->closeAuthModal();
            $this->syncPlanStatus();
            Session::flash('success', __('План лікування успішно активовано (за документами пацієнта).'));

            return;
        }

        try {
            $response = app(CarePlanApprovalService::class)->verify(
                $this->carePlan->person->uuid,
                $this->approvalId,
                (int) $this->verificationCode,
            );

            if ($response->successful()) {
                $this->closeAuthModal();
                $this->syncPlanStatus();
                Session::flash('success', __('План лікування успішно активовано.'));
            }
        } catch (Exception $e) {
            Log::error('CarePlanLifecycle: failed to verify approval: ' . $e->getMessage());
            $this->addError('verificationCode', __('Невірний код підтвердження або помилка сервісу'));
        }
    }

    public function resendSms(): void
    {
        if ($this->smsResent) {
            return;
        }
        try {
            app(CarePlanApprovalService::class)->resendSms($this->carePlan->person->uuid, $this->approvalId);
            $this->smsResent = true;
            Session::flash('success', __('SMS надіслано повторно'));
        } catch (Exception $e) {
            Log::error('CarePlanLifecycle: failed to resend SMS: ' . $e->getMessage());
            $message = str_contains($e->getMessage(), 'ACL')
                ? __('care-plan.sms_resend_acl_error')
                : __('care-plan.sms_resend_error');
            $this->addError('verificationCode', $message);
            Session::flash('error', $message);
        }
    }

    public function sync(): void
    {
        if (empty($this->carePlan->uuid)) {
            Session::flash('error', __('care-plan.care_plan_not_synced'));

            return;
        }

        if (!$this->syncPlanStatus()) {
            Session::flash('error', __('care-plan.sync_error'));

            return;
        }

        Session::flash('success', __('care-plan.data_synced'));
    }

    protected function buildCarePlanStatusChangePayload(array $statusReasonCodeableConcept): array
    {
        $api = EHealth::carePlan();

        return $api->buildCancelContent($api->fetchCancelSnapshot($this->carePlan->person->uuid, $this->carePlan->uuid), $statusReasonCodeableConcept);
    }

    public function syncPlanStatus(): bool
    {
        try {
            EHealth::carePlan()->synchronizeResources([['id' => $this->carePlan->uuid]], $this->carePlan->personId, $this->carePlan->legalEntityId, $this->carePlan->person->uuid);
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
