<?php

declare(strict_types=1);

namespace App\Services\MedicalEvents;

use App\Classes\eHealth\EHealth;
use App\Classes\eHealth\EHealthResponse;
use App\Enums\CarePlanTermsOfService;
use App\Enums\Person\ApprovalStatus;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Jobs\RemoteEHealthLinksProcessing;
use App\Models\CarePlan;
use App\Models\LegalEntity;
use App\Models\MedicalEvents\Sql\Approval;
use App\Models\User;
use App\Repositories\MedicalEvents\Repository;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use RuntimeException;

/**
 * Care Plan adapter over shared MedicalEvents ApprovalRepository + eHealth Approval API.
 *
 * Do not route CarePlan through HasApproval: that trait uses $model->uuid as patient id.
 */
class CarePlanApprovalService
{
    /**
     * @return array<string, mixed>
     */
    public function buildCreatePayload(
        CarePlan $carePlan,
        string $employeeUuid,
        string $accessLevel = 'write',
        ?string $authorizeWith = null,
        ?LegalEntity $legalEntity = null,
    ): array {
        $payload = [
            'resources' => [
                [
                    'identifier' => [
                        'type' => [
                            'coding' => [['system' => 'eHealth/resources', 'code' => 'care_plan']],
                        ],
                        'value' => $carePlan->uuid,
                    ],
                ],
            ],
            'granted_to' => [
                'identifier' => [
                    'type' => [
                        'coding' => [['system' => 'eHealth/resources', 'code' => 'employee']],
                    ],
                    'value' => $employeeUuid,
                ],
            ],
            'access_level' => $accessLevel,
            'authorize_with' => $authorizeWith ?: null,
        ];

        if ($this->skipsPatientOtp($carePlan, $legalEntity) || empty($payload['authorize_with'])) {
            unset($payload['authorize_with']);
        }

        return $payload;
    }

    /**
     * INPATIENT plans at the managing organisation skip patient OTP.
     *
     * eHealth does not send SMS and accepts a separate verification without a code.
     *
     * @see https://e-health-ua.atlassian.net/wiki/spaces/EH/pages/17629119758/RC._Create+Approval_EN
     */
    public function skipsPatientOtp(CarePlan $carePlan, ?LegalEntity $legalEntity = null): bool
    {
        $legalEntity ??= legalEntity();
        if ($legalEntity === null) {
            return false;
        }

        $terms = $carePlan->termsOfServiceCode();
        if (is_array($terms)) {
            $terms = $terms['coding'][0]['code'] ?? $terms['code'] ?? '';
        }

        if (strtoupper(trim((string) $terms)) !== CarePlanTermsOfService::INPATIENT->value) {
            return false;
        }

        $managingOrganizationUuid = $carePlan->managingOrganization?->value;
        if (filled($managingOrganizationUuid)) {
            return $managingOrganizationUuid === $legalEntity->uuid;
        }

        $planLegalEntityId = $carePlan->legalEntityId;
        if ($planLegalEntityId === null || $planLegalEntityId === '') {
            return false;
        }

        return (int) $planLegalEntityId === (int) $legalEntity->id;
    }

    public function resolveAccessLevel(CarePlan $carePlan, ?LegalEntity $legalEntity = null): string
    {
        $legalEntity ??= legalEntity();

        $managingOrganizationUuid = $carePlan->managingOrganization?->value;
        $isManagingOrganization = filled($managingOrganizationUuid)
            ? $managingOrganizationUuid === $legalEntity?->uuid
            : (int) $carePlan->legalEntityId === (int) $legalEntity?->id;

        return $isManagingOrganization ? 'write' : 'read';
    }

    /**
     * Create a care_plan approval in eHealth and persist local Approval / async job state.
     *
     * eHealth may answer 202, in which case processing continues on the queue: $user and
     * $bearerToken are what let that queued job keep acting as the caller, so they must be
     * supplied by the caller rather than read from the session here.
     *
     * @throws \Throwable Propagates eHealth client exceptions to the caller for UX handling.
     */
    public function create(
        CarePlan $carePlan,
        string $patientUuid,
        string $employeeUuid,
        string $accessLevel = 'write',
        ?string $authorizeWith = null,
        ?LegalEntity $legalEntity = null,
        ?User $user = null,
        ?string $bearerToken = null,
    ): CarePlanApprovalCreateResult {
        app(\App\Repositories\CarePlanRepository::class)->assertApprovalCreationReady($carePlan);
        $legalEntity ??= legalEntity();
        if (!$legalEntity || !$user || !$bearerToken || $patientUuid !== $carePlan->person?->uuid) {
            throw new RuntimeException(__('care-plan.approval_create_error'));
        }
        $repository = Repository::approval();
        app(\App\Repositories\CarePlanRepository::class)->assertApprovalRecipient($employeeUuid, $legalEntity->id);
        $confirmWithoutOtp = $this->skipsPatientOtp($carePlan, $legalEntity);
        $lock = Cache::lock('care-plan-approval-create:'.$carePlan->id.':'.$employeeUuid.':'.$accessLevel, 120);
        if (!$lock->get()) {
            throw new RuntimeException(__('care-plan.approval_processing'));
        }
        $link = null;
        $creationConfirmed = false;
        try {
            $existing = $repository->reusableForCarePlan($carePlan, $employeeUuid, $accessLevel);
            if ($existing !== null) {
                if (($cancel = $repository->cancellationLinkFor($existing)) !== null && $cancel->status !== 'FAILED') {
                    throw new RuntimeException(__('care-plan.approval_cancel_processing'));
                }
                if ($existing->getAttribute('is_verified') && ApprovalStatus::resolve($existing->status)?->isGranted()) {
                    return new CarePlanApprovalCreateResult(CarePlanApprovalCreateOutcome::Granted, $existing->uuid);
                }
                $existingLink = $repository->creationLinkFor($existing);
                if ($confirmWithoutOtp && ($existingLink === null || $existingLink->status === 'PROCESSED')) {
                    $this->confirmWithoutOtp($patientUuid, $existing->uuid, $bearerToken);

                    return new CarePlanApprovalCreateResult(CarePlanApprovalCreateOutcome::Granted, $existing->uuid);
                }
                if ($existingLink !== null && $existingLink->status !== 'FAILED') {
                    return $this->resultForLink($existingLink->id);
                }
                $data = EHealth::approval()->withToken($bearerToken)->getApprovalDetails($patientUuid, $existing->uuid)->validate();
                $repository->sync($data, $carePlan, $existing);

                return $this->resultFromData($data);
            }
            $payload = $this->buildCreatePayload($carePlan, $employeeUuid, $accessLevel, $authorizeWith, $legalEntity);
            $link = $repository->beginCarePlanRequest($carePlan, $payload, $confirmWithoutOtp);
            $response = $this->requireSuccessfulResponse(EHealth::approval()->withToken($bearerToken)->createApproval($patientUuid, $payload));
            $data = $response->validate();
            if ($response->getStatusCode() === 202) {
                $href = collect($data['links'] ?? [])->first(fn (array $candidate): bool => str_contains((string) ($candidate['href'] ?? ''), '/jobs/'))['href'] ?? null;
                if (!$href) {
                    throw new RuntimeException(__('care-plan.submission_unconfirmed'));
                }
                $repository->trackRequest($link, $href, $data);
                Bus::batch([new RemoteEHealthLinksProcessing($link, $legalEntity, standalone: true)])
                    ->withOption('legal_entity_id', $legalEntity->id)
                    ->withOption('token', Crypt::encryptString($bearerToken))
                    ->withOption('user', $user)
                    ->name(RemoteEHealthLinksProcessing::BATCH_NAME)->onQueue('sync')->dispatch();

                return new CarePlanApprovalCreateResult(CarePlanApprovalCreateOutcome::Async, pollingLinkId: $link->id);
            }
            $repository->completeCarePlanRequest($link, $data);
            $creationConfirmed = true;
            if ($confirmWithoutOtp && empty($data['is_verified'])) {
                $this->confirmWithoutOtp($patientUuid, $data['id'], $bearerToken);

                return new CarePlanApprovalCreateResult(CarePlanApprovalCreateOutcome::Granted, $data['id']);
            }

            return $this->resultFromData($data);
        } catch (\Throwable $exception) {
            if ($link !== null && !$creationConfirmed && ($exception instanceof \App\Exceptions\EHealth\EHealthValidationException
                || ($exception instanceof EHealthResponseException && $exception->getCode() >= 400 && $exception->getCode() < 500 && $exception->getCode() !== 408))) {
                RemoteEHealthLinksProcessing::markSubmissionFailed($link);
            }
            throw $exception;
        } finally {
            $lock->release();
        }
    }

    private function resultForLink(int $linkId): CarePlanApprovalCreateResult
    {
        $result = $this->resolveAsyncJob($linkId);
        if ($result->isFailed()) {
            throw new RuntimeException($result->errorMessage ?: __('care-plan.approval_result_unavailable'));
        }
        if ($result->isGranted()) {
            return new CarePlanApprovalCreateResult(CarePlanApprovalCreateOutcome::Granted, $result->approvalId);
        }
        if ($result->requiresOtp()) {
            return new CarePlanApprovalCreateResult(CarePlanApprovalCreateOutcome::OtpRequired, $result->approvalId, authMethod: $result->authMethod);
        }

        return new CarePlanApprovalCreateResult(CarePlanApprovalCreateOutcome::Async, pollingLinkId: $linkId);
    }

    private function resultFromData(array $data): CarePlanApprovalCreateResult
    {
        if ($this->approvalIsGranted($data)) {
            return new CarePlanApprovalCreateResult(CarePlanApprovalCreateOutcome::Granted, $data['id']);
        }
        $method = $this->extractAuthMethod($data);
        if (isset($data['id']) && in_array($method['type'] ?? null, ['OTP', 'OFFLINE', 'THIRD_PERSON'], true)) {
            return new CarePlanApprovalCreateResult(CarePlanApprovalCreateOutcome::OtpRequired, $data['id'], authMethod: $method);
        }

        throw new RuntimeException(__('care-plan.approval_result_unavailable'));
    }

    private function approvalIsGranted(array $data): bool
    {
        return ($data['is_verified'] ?? false) === true
            && ApprovalStatus::resolve($data['status'] ?? 'active')?->isGranted()
            && (empty($data['expires_at']) || \Carbon\CarbonImmutable::parse($data['expires_at'])->isFuture());
    }

    public function verify(string $patientUuid, string $approvalId, ?int $code, ?string $bearerToken = null): EHealthResponse
    {
        $api = EHealth::approval();
        if ($bearerToken !== null) {
            $api->withToken($bearerToken);
        }
        try {
            $response = $this->requireSuccessfulResponse($api->verify($patientUuid, $approvalId, $code === null ? [] : ['code' => $code]));
        } catch (EHealthResponseException $exception) {
            if ($exception->response->status() !== 409 || \App\Classes\eHealth\Api\Approval::errorMessage($exception->response) !== 'Approval is already verified') {
                throw $exception;
            }
            // The remote PATCH can succeed before local persistence fails. Reconcile this exact approval.
            $response = $this->requireSuccessfulResponse($api->getApprovalDetails($patientUuid, $approvalId));
            $confirmed = $response->validate();
            if (($confirmed['id'] ?? null) !== $approvalId || !$this->approvalIsGranted($confirmed)) {
                throw $exception;
            }
        }
        try {
            $data = $response->validate();
        } catch (\Illuminate\Validation\ValidationException $exception) {
            if ($code === null) {
                throw $exception;
            }
            // The code may already be consumed. Re-read this approval instead of sending the code again.
            $confirmedResponse = $this->requireSuccessfulResponse($api->getApprovalDetails($patientUuid, $approvalId));
            $data = $confirmedResponse->validate();
            if (($data['id'] ?? null) !== $approvalId || !$this->approvalIsGranted($data)) {
                throw $exception;
            }
            $response = $confirmedResponse;
        }
        if ((array_key_exists('is_verified', $data) && $data['is_verified'] !== true)
            || (isset($data['status']) && ApprovalStatus::resolve($data['status'])?->isGranted() !== true)) {
            throw new RuntimeException(__('care-plan.approval_verify_error'));
        }
        Repository::approval()->storePatientResponse($patientUuid, $approvalId, $data, ApprovalStatus::ACTIVE->value);

        return $response;
    }

    /**
     * Confirm an INPATIENT same-org approval without an OTP code.
     */
    public function confirmWithoutOtp(string $patientUuid, string $approvalId, ?string $bearerToken = null): EHealthResponse
    {
        return $this->verify($patientUuid, $approvalId, null, $bearerToken);
    }

    /** @return array{status: string, linkId: int} */
    public function requestCancellation(CarePlan $plan, string $approvalId, LegalEntity $legalEntity, User $user, string $bearerToken): array
    {
        if ($bearerToken === '' || $plan->legalEntityId !== $legalEntity->id || $user->cannot('cancelApproval', $plan)) {
            throw new RuntimeException(__('care-plan.approval_cancel_error'));
        }
        $repository = Repository::approval();
        $link = $repository->beginCarePlanCancellation($plan, $approvalId);
        if (!$link->wasRecentlyCreated && $link->status === 'PROCESSED') {
            return ['status' => 'processed', 'linkId' => $link->id];
        }
        try {
            if ($link->wasRecentlyCreated) {
                $response = $this->requireSuccessfulResponse(EHealth::approval()->withToken($bearerToken)
                    ->cancelApproval($plan->person->uuid, $approvalId));
                $data = $response->validate();
                if ($response->status() === 201) {
                    $result = ['status' => 'processed', 'status_code' => 201, 'response_data' => $data];
                    $link->job()->update(['response_data' => $result]);
                    $repository->finishCarePlanCancellation($link, $result);

                    return ['status' => 'processed', 'linkId' => $link->id];
                }
                $href = collect($data['links'] ?? [])->first(fn (array $candidate): bool =>
                    preg_match('~/(?:api/)?jobs/[^/?#]+$~i', (string) ($candidate['href'] ?? '')) === 1)['href'] ?? null;
                if (!$href) {
                    throw new RuntimeException(__('care-plan.approval_cancel_unconfirmed'));
                }
                $repository->trackRequest($link, $href, $data);
            } elseif ($link->href === null && data_get($link->job->response_data, 'status_code') === 201) {
                $repository->finishCarePlanCancellation($link, $link->job->response_data);

                return ['status' => 'processed', 'linkId' => $link->id];
            } elseif ($link->href === null) {
                return ['status' => 'unknown', 'linkId' => $link->id];
            }
            // Retrying a saved receipt only polls the original remote job; it never repeats cancellation.
            $link->update(['error' => null]);
            Bus::batch([new RemoteEHealthLinksProcessing($link, $legalEntity, standalone: true)])
                ->withOption('legal_entity_id', $legalEntity->id)
                ->withOption('token', Crypt::encryptString($bearerToken))
                ->withOption('user', $user)
                ->name(RemoteEHealthLinksProcessing::BATCH_NAME)->onQueue('sync')->dispatch();

            return ['status' => 'pending', 'linkId' => $link->id];
        } catch (\Throwable $exception) {
            if ($exception instanceof \App\Exceptions\EHealth\EHealthValidationException
                || ($exception instanceof EHealthResponseException && in_array($exception->getCode(), [400, 401, 403, 404, 409, 422, 429], true))) {
                $repository->finishCarePlanCancellation($link, ['status' => 'failed']);
            } else {
                $link->update(['error' => ['message' => __('care-plan.approval_cancel_unconfirmed')]]);
            }
            throw $exception;
        }
    }

    /** Transport exceptions propagate to the UI; a non-success response cannot grant access. */
    protected function requireSuccessfulResponse(EHealthResponse $response): EHealthResponse
    {
        if (!$response->successful()) {
            throw new EHealthResponseException($response);
        }

        return $response;
    }

    public function resendSms(string $patientUuid, string $approvalId, ?string $bearerToken = null): EHealthResponse
    {
        $key = 'care-plan-otp-resend:'.$patientUuid.':'.$approvalId;

        if (!Cache::add($key, true, now()->addMinutes(10))) {
            throw new RuntimeException(__('validation.sms_already_resent'));
        }

        try {
            $api = EHealth::approval();
            if ($bearerToken !== null) {
                $api->withToken($bearerToken);
            }

            return $this->requireSuccessfulResponse($api->resendSms($patientUuid, $approvalId));
        } catch (\Throwable $exception) {
            if ($exception instanceof \App\Exceptions\EHealth\EHealthValidationException
                || ($exception instanceof EHealthResponseException && $exception->getCode() >= 400 && $exception->getCode() < 500 && $exception->getCode() !== 408)) {
                Cache::forget($key);
            }
            throw $exception;
        }
    }

    public function syncForCarePlan(CarePlan $carePlan): void
    {
        // Uses Get approvals filters (granted_resource_type + granted_resources) via syncApprovals.
        Repository::approval()->syncApprovals($carePlan, 'care_plan', []);
    }

    /** A resend may be async; only its completed job confirms the SMS request. */
    public function requestSmsResend(CarePlan $plan, string $approvalId, LegalEntity $legalEntity, User $user, string $bearerToken): array
    {
        $patientUuid = $plan->person->uuid;
        $api = EHealth::approval()->withToken($bearerToken);
        $approval = Repository::approval()->carePlanApproval($plan, $approvalId);
        if ($approval->getAttribute('is_verified') && ApprovalStatus::resolve($approval->status)?->isGranted()
            && ($approval->expiresAt === null || $approval->expiresAt->isFuture())) {
            return ['status' => 'granted'];
        }
        if ($this->skipsPatientOtp($plan, $legalEntity)) {
            throw new RuntimeException(__('care-plan.sms_not_required'));
        }
        try {
            $response = $this->resendSms($patientUuid, $approvalId, $bearerToken);
        } catch (EHealthResponseException $exception) {
            if ($exception->response->status() !== 409 || \App\Classes\eHealth\Api\Approval::errorMessage($exception->response) !== 'Approval is already verified') {
                throw $exception;
            }
            $details = $this->requireSuccessfulResponse($api->getApprovalDetails($patientUuid, $approvalId));
            $confirmed = $details->validate();
            if (($confirmed['id'] ?? null) !== $approvalId || !$this->approvalIsGranted($confirmed)) {
                throw $exception;
            }
            Repository::approval()->storePatientResponse($patientUuid, $approvalId, $confirmed, ApprovalStatus::ACTIVE->value);

            return ['status' => 'granted'];
        }
        $link = Repository::approval()->beginCarePlanSmsResend($plan, $approvalId);
        if ($response->getStatusCode() === 202) {
            $jobData = $response->validate();
            $href = collect($jobData['links'] ?? [])->first(fn (array $candidate): bool => str_contains((string) ($candidate['href'] ?? ''), '/jobs/'))['href'] ?? null;
            if (!$href) {
                Repository::approval()->finishCarePlanSmsResend($link, ['status' => 'failed']);
                throw new RuntimeException(__('care-plan.submission_unconfirmed'));
            }
            Repository::approval()->trackRequest($link, $href, $jobData);
            try {
                Bus::batch([new RemoteEHealthLinksProcessing($link, $legalEntity, standalone: true)])
                    ->withOption('legal_entity_id', $legalEntity->id)
                    ->withOption('token', Crypt::encryptString($bearerToken))
                    ->withOption('user', $user)
                    ->name(RemoteEHealthLinksProcessing::BATCH_NAME)->onQueue('sync')->dispatch();
            } catch (\Throwable $exception) {
                Repository::approval()->finishCarePlanSmsResend($link, ['status' => 'failed']);
                throw $exception;
            }

            return ['status' => 'pending', 'linkId' => $link->id];
        }
        Repository::approval()->finishCarePlanSmsResend($link, ['status' => 'processed', 'response_data' => $response->validate()]);

        return ['status' => 'processed'];
    }

    /**
     * Resolve Livewire poll status for an async create job.
     */
    public function resolveAsyncJob(int $pollingLinkId): CarePlanApprovalJobStatusResult
    {
        $link = Repository::approval()->requestLink($pollingLinkId);
        if (!$link || !$link->job || !$link->linkable instanceof Approval) {
            return new CarePlanApprovalJobStatusResult(CarePlanApprovalJobOutcome::Failed, errorMessage: __('care-plan.approval_result_unavailable'));
        }
        if (strtoupper((string) $link->job->status) === 'FAILED') {
            return new CarePlanApprovalJobStatusResult(CarePlanApprovalJobOutcome::Failed, errorMessage: $this->formatJobError($link->job->response_data ?? []));
        }
        $approval = $link->linkable;
        if ($approval->getAttribute('is_verified') && ApprovalStatus::resolve($approval->status)?->isGranted()
            && ($approval->expiresAt === null || $approval->expiresAt->isFuture())) {
            return new CarePlanApprovalJobStatusResult(CarePlanApprovalJobOutcome::Granted, $approval->uuid);
        }
        if (!empty($link->job->response_data['confirmationError'])) {
            return new CarePlanApprovalJobStatusResult(CarePlanApprovalJobOutcome::Failed, errorMessage: __('care-plan.approval_verify_error'));
        }
        if (strtoupper((string) $link->job->status) !== 'PROCESSED') {
            return new CarePlanApprovalJobStatusResult(CarePlanApprovalJobOutcome::Pending);
        }
        try {
            $data = \App\Classes\eHealth\Api\Approval::validateResourceData($link->job->response_data ?? []);
            if (($data['authentication_method_current'] ?? null) === null && $approval->approvable instanceof CarePlan
                && $this->skipsPatientOtp($approval->approvable)) {
                return new CarePlanApprovalJobStatusResult(CarePlanApprovalJobOutcome::Pending);
            }
            $result = $this->resultFromData($data);
        } catch (\Illuminate\Validation\ValidationException) {
            return new CarePlanApprovalJobStatusResult(CarePlanApprovalJobOutcome::Failed, errorMessage: __('care-plan.approval_result_unavailable'));
        } catch (RuntimeException $exception) {
            return new CarePlanApprovalJobStatusResult(CarePlanApprovalJobOutcome::Failed, errorMessage: $exception->getMessage());
        }

        return new CarePlanApprovalJobStatusResult(
            $result->isGranted() ? CarePlanApprovalJobOutcome::Granted : CarePlanApprovalJobOutcome::OtpRequired,
            $result->approvalId,
            $result->authMethod,
        );
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>|null
     */
    private function extractAuthMethod(array $data): ?array
    {
        $method = $data['response_data']['authentication_method_current']
            ?? $data['data']['authentication_method_current']
            ?? $data['authentication_method_current']
            ?? $data['urgent']['authentication_method_current']
            ?? null;

        return is_array($method) ? $method + ['phone_number' => $method['phoneNumber'] ?? $method['number'] ?? null] : null;
    }

    /**
     * @param  array<string, mixed>  $jobResult
     */
    private function formatJobError(array $jobResult): string
    {
        if (isset($jobResult['error']['invalid']) && is_array($jobResult['error']['invalid'])) {
            $errors = [];

            foreach ($jobResult['error']['invalid'] as $invalid) {
                $entry = $invalid['entry'] ?? '';
                $rules = $invalid['rules'] ?? [];

                foreach ($rules as $rule) {
                    $errors[] = ($entry ? $entry.': ' : '').($rule['description'] ?? '');
                }
            }

            if ($errors !== []) {
                return 'Помилка від ЕСОЗ: '.implode(', ', $errors);
            }
        }

        if (isset($jobResult['error']['message'])) {
            return 'Помилка від ЕСОЗ: '.$jobResult['error']['message'];
        }

        return __('care-plan.approval_create_error');
    }
}
