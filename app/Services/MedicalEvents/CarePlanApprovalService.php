<?php

declare(strict_types=1);

namespace App\Services\MedicalEvents;

use App\Classes\eHealth\Api\Approval as ApprovalApi;

use App\Classes\eHealth\EHealth;
use App\Classes\eHealth\EHealthResponse;
use App\Enums\CarePlanTermsOfService;
use App\Enums\Person\AuthenticationMethod;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Exceptions\EHealth\EHealthValidationException;
use App\Jobs\RemoteEHealthLinksProcessing;
use App\Models\CarePlan;
use App\Models\LegalEntity;
use App\Models\MedicalEvents\Sql\Approval;
use App\Models\User;
use App\Repositories\MedicalEvents\Repository as MedicalEventsRepository;
use App\Repositories\Repository;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

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
            $terms = $terms['coding'][0]['code'] ?? ($terms['code'] ?? '');
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
     * @throws Throwable Propagates eHealth client exceptions to the caller for UX handling.
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
        Repository::carePlan()->assertApprovalCreationReady($carePlan);
        $legalEntity ??= legalEntity();
        if (!$legalEntity || !$user || !$bearerToken || $patientUuid !== $carePlan->person?->uuid) {
            throw new RuntimeException(__('care-plan.approval_create_error'));
        }
        $repository = MedicalEventsRepository::approval();
        Repository::carePlan()->assertApprovalRecipient($employeeUuid, $legalEntity->id);
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
                if ($existing->getAttribute('is_verified') && $existing->expiresAt?->isFuture() === true) {
                    return new CarePlanApprovalCreateResult(CarePlanApprovalCreateOutcome::Granted, $existing->uuid);
                }
                $existingLink = $existing->ehealthLinks->filter(fn ($candidate): bool => data_get($candidate->job?->request_data, 'operation') !== 'resendSms')->sortByDesc('id')->first();
                if ($existingLink !== null && !in_array($existingLink->status, ['PROCESSED', 'FAILED'], true)) {
                    return $this->resultForLink($existingLink->id);
                }
                try {
                    $data = $this->requireSuccessfulResponse(EHealth::approval()->withToken($bearerToken)->getApprovalDetails($patientUuid, $existing->uuid))->validate();
                } catch (EHealthResponseException $exception) {
                    if ($exception->response->status() !== 404) {
                        throw $exception;
                    }
                    $repository->markUnavailable($existing);
                    $data = []; // A missing remote resource expires local access without inventing approval fields.
                }
                if ($data !== []) {
                    $repository->sync($data, $carePlan, $existing);
                }
                $existing->refresh();
                if ($this->approvalIsGranted($data)) {
                    return new CarePlanApprovalCreateResult(CarePlanApprovalCreateOutcome::Granted, $existing->uuid);
                }
                if (!$existing->getAttribute('is_verified') && $existing->expiresAt?->isFuture() === true
                    && ($confirmWithoutOtp || $authorizeWith === null || $existing->authorizeWith === $authorizeWith)) {
                    if ($confirmWithoutOtp) {
                        $this->confirmWithoutOtp($patientUuid, $existing->uuid, $bearerToken);

                        return new CarePlanApprovalCreateResult(CarePlanApprovalCreateOutcome::Granted, $existing->uuid);
                    }

                    return $this->resultFromData($data, $patientUuid, $existing->authorizeWith);
                }
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

            return $this->resultFromData($data, $patientUuid, $authorizeWith);
        } catch (Throwable $exception) {
            if ($link !== null && !$creationConfirmed && ($exception instanceof EHealthValidationException
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
        if ($result->requiresConfirmation()) {
            return new CarePlanApprovalCreateResult($result->requiresOtp() ? CarePlanApprovalCreateOutcome::OtpRequired : CarePlanApprovalCreateOutcome::OfflineRequired, $result->approvalId, authMethod: $result->authMethod);
        }

        return new CarePlanApprovalCreateResult(CarePlanApprovalCreateOutcome::Async, pollingLinkId: $linkId);
    }

    public function pendingConfirmation(string $patientUuid, string $approvalId, ?string $authorizeWith = null): CarePlanApprovalCreateResult
    {
        $response = $this->requireSuccessfulResponse(EHealth::approval()->getApprovalDetails($patientUuid, $approvalId));
        $data = $response->validate();
        if (($data['id'] ?? null) !== $approvalId) {
            throw new RuntimeException(__('care-plan.approval_result_unavailable'));
        }
        $result = $this->resultFromData($data, $patientUuid, $authorizeWith);
        if ($result->isGranted()) {
            MedicalEventsRepository::approval()->storePatientResponse($patientUuid, $approvalId, $data);
        }

        return $result;
    }

    private function resultFromData(array $data, ?string $patientUuid = null, ?string $authorizeWith = null): CarePlanApprovalCreateResult
    {
        if ($this->approvalIsGranted($data)) {
            return new CarePlanApprovalCreateResult(CarePlanApprovalCreateOutcome::Granted, $data['id']);
        }
        if (($data['is_verified'] ?? null) !== false || empty($data['expires_at'])
            || !CarbonImmutable::parse($data['expires_at'])->isFuture()) {
            throw new RuntimeException(__('care-plan.approval_result_unavailable'));
        }
        $method = $this->extractAuthMethod($data);
        $method = app(PatientApprovalService::class)->resolveConfirmationMethod($method ?? [], $patientUuid, $data['authorize_with'] ?? $authorizeWith);
        if (isset($data['id']) && AuthenticationMethod::confirmationMethod($method ?? []) !== null) {
            $outcome = AuthenticationMethod::confirmationMethod($method) === AuthenticationMethod::OTP
                ? CarePlanApprovalCreateOutcome::OtpRequired : CarePlanApprovalCreateOutcome::OfflineRequired;

            return new CarePlanApprovalCreateResult($outcome, $data['id'], authMethod: $method);
        }

        throw new RuntimeException(__('care-plan.approval_result_unavailable'));
    }

    private function approvalIsGranted(array $data): bool
    {
        return ($data['is_verified'] ?? false) === true
            && !empty($data['expires_at'])
            && CarbonImmutable::parse($data['expires_at'])->isFuture();
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
            if ($exception->response->status() !== 409 || ApprovalApi::errorMessage($exception->response) !== 'Approval is already verified') {
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
        } catch (ValidationException $exception) {
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
        if (array_key_exists('is_verified', $data) && $data['is_verified'] !== true) {
            throw new RuntimeException(__('care-plan.approval_verify_error'));
        }
        // A successful PATCH may contain only an acknowledgement. Read the verified flag and expiry before granting access.
        if ($data === [] || !isset($data['id'], $data['is_verified'], $data['expires_at'])) {
            $response = $this->requireSuccessfulResponse($api->getApprovalDetails($patientUuid, $approvalId));
            $data = $response->validate();
        }
        if (($data['id'] ?? null) !== $approvalId || !$this->approvalIsGranted($data)) {
            throw new RuntimeException(__('care-plan.approval_verify_error'));
        }
        MedicalEventsRepository::approval()->storePatientResponse($patientUuid, $approvalId, $data);

        return $response;
    }

    /**
     * Confirm an INPATIENT same-org approval without an OTP code.
     */
    public function confirmWithoutOtp(string $patientUuid, string $approvalId, ?string $bearerToken = null): EHealthResponse
    {
        return $this->verify($patientUuid, $approvalId, null, $bearerToken);
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
        } catch (Throwable $exception) {
            if ($exception instanceof EHealthValidationException
                || ($exception instanceof EHealthResponseException && $exception->getCode() >= 400 && $exception->getCode() < 500 && $exception->getCode() !== 408)) {
                Cache::forget($key);
            }
            throw $exception;
        }
    }

    public function syncForCarePlan(CarePlan $carePlan): void
    {
        // Uses Get approvals filters (granted_resource_type + granted_resources) via syncApprovals.
        MedicalEventsRepository::approval()->syncApprovals($carePlan, 'care_plan', []);
    }

    /** A resend may be async; only its completed job confirms the SMS request. */
    public function requestSmsResend(CarePlan $plan, string $approvalId, LegalEntity $legalEntity, User $user, string $bearerToken): array
    {
        $patientUuid = $plan->person->uuid;
        $api = EHealth::approval()->withToken($bearerToken);
        $approval = MedicalEventsRepository::approval()->carePlanApproval($plan, $approvalId);
        if ($approval->getAttribute('is_verified') && $approval->expiresAt?->isFuture() === true) {
            return ['status' => 'granted'];
        }
        if ($approval->expiresAt?->isFuture() !== true) {
            throw new RuntimeException(__('care-plan.approval_result_unavailable'));
        }
        if ($this->skipsPatientOtp($plan, $legalEntity)) {
            throw new RuntimeException(__('care-plan.sms_not_required'));
        }
        try {
            $response = $this->resendSms($patientUuid, $approvalId, $bearerToken);
        } catch (EHealthResponseException $exception) {
            if ($exception->response->status() !== 409 || ApprovalApi::errorMessage($exception->response) !== 'Approval is already verified') {
                throw $exception;
            }
            $details = $this->requireSuccessfulResponse($api->getApprovalDetails($patientUuid, $approvalId));
            $confirmed = $details->validate();
            if (($confirmed['id'] ?? null) !== $approvalId || !$this->approvalIsGranted($confirmed)) {
                throw $exception;
            }
            MedicalEventsRepository::approval()->storePatientResponse($patientUuid, $approvalId, $confirmed);

            return ['status' => 'granted'];
        }
        $link = MedicalEventsRepository::approval()->beginCarePlanSmsResend($plan, $approvalId);
        if ($response->getStatusCode() === 202) {
            $jobData = $response->validate();
            $href = collect($jobData['links'] ?? [])->first(fn (array $candidate): bool => str_contains((string) ($candidate['href'] ?? ''), '/jobs/'))['href'] ?? null;
            if (!$href) {
                MedicalEventsRepository::approval()->finishCarePlanSmsResend($link, ['status' => 'failed']);
                throw new RuntimeException(__('care-plan.submission_unconfirmed'));
            }
            MedicalEventsRepository::approval()->trackRequest($link, $href, $jobData);
            try {
                Bus::batch([new RemoteEHealthLinksProcessing($link, $legalEntity, standalone: true)])
                    ->withOption('legal_entity_id', $legalEntity->id)
                    ->withOption('token', Crypt::encryptString($bearerToken))
                    ->withOption('user', $user)
                    ->name(RemoteEHealthLinksProcessing::BATCH_NAME)->onQueue('sync')->dispatch();
            } catch (Throwable $exception) {
                MedicalEventsRepository::approval()->finishCarePlanSmsResend($link, ['status' => 'failed']);
                throw $exception;
            }

            return ['status' => 'pending', 'linkId' => $link->id];
        }
        MedicalEventsRepository::approval()->finishCarePlanSmsResend($link, ['status' => 'processed', 'response_data' => $response->validate()]);

        return ['status' => 'processed'];
    }

    /**
     * Resolve Livewire poll status for an async create job.
     */
    public function resolveAsyncJob(int $pollingLinkId): CarePlanApprovalJobStatusResult
    {
        $link = MedicalEventsRepository::approval()->requestLink($pollingLinkId);
        if (!$link || !$link->job || !$link->linkable instanceof Approval) {
            return new CarePlanApprovalJobStatusResult(CarePlanApprovalJobOutcome::Failed, errorMessage: __('care-plan.approval_result_unavailable'));
        }
        if (strtoupper((string) $link->job->status) === 'FAILED') {
            return new CarePlanApprovalJobStatusResult(CarePlanApprovalJobOutcome::Failed, errorMessage: $this->formatJobError($link->job->response_data ?? []));
        }
        $approval = $link->linkable;
        if ($approval->getAttribute('is_verified') && $approval->expiresAt?->isFuture() === true) {
            return new CarePlanApprovalJobStatusResult(CarePlanApprovalJobOutcome::Granted, $approval->uuid);
        }
        if ($approval->expiresAt !== null && !$approval->expiresAt->isFuture()) {
            return new CarePlanApprovalJobStatusResult(CarePlanApprovalJobOutcome::Failed, errorMessage: __('care-plan.approval_result_unavailable'));
        }
        if (!empty($link->job->response_data['confirmationError'])) {
            return new CarePlanApprovalJobStatusResult(CarePlanApprovalJobOutcome::Failed, errorMessage: __('care-plan.approval_verify_error'));
        }
        if (strtoupper((string) $link->job->status) !== 'PROCESSED') {
            return new CarePlanApprovalJobStatusResult(CarePlanApprovalJobOutcome::Pending);
        }
        try {
            $data = ApprovalApi::validateResourceData($link->job->response_data ?? []);
            if (($data['authentication_method_current'] ?? null) === null && $approval->approvable instanceof CarePlan
                && $this->skipsPatientOtp($approval->approvable)) {
                return new CarePlanApprovalJobStatusResult(CarePlanApprovalJobOutcome::Pending);
            }
            $authorizeWith = $approval->authorizeWith ?: data_get($link->job?->request_data, 'authorize_with');
            $result = $this->resultFromData($data, $approval->approvable?->person?->uuid, $authorizeWith);
        } catch (ValidationException) {
            return new CarePlanApprovalJobStatusResult(CarePlanApprovalJobOutcome::Failed, errorMessage: __('care-plan.approval_result_unavailable'));
        } catch (RuntimeException $exception) {
            return new CarePlanApprovalJobStatusResult(CarePlanApprovalJobOutcome::Failed, errorMessage: $exception->getMessage());
        }

        return new CarePlanApprovalJobStatusResult(
            $result->isGranted() ? CarePlanApprovalJobOutcome::Granted
                : ($result->requiresOtp() ? CarePlanApprovalJobOutcome::OtpRequired : CarePlanApprovalJobOutcome::OfflineRequired),
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
            ?? ($data['data']['authentication_method_current']
                ?? ($data['authentication_method_current']
                    ?? ($data['urgent']['authentication_method_current'] ?? null)));

        return is_array($method) ? $method : null;
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
