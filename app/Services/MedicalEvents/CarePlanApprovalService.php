<?php

declare(strict_types=1);

namespace App\Services\MedicalEvents;

use App\Classes\eHealth\EHealth;
use App\Classes\eHealth\EHealthResponse;
use App\Enums\Person\ApprovalStatus;
use App\Jobs\RemoteEHealthLinksProcessing;
use App\Models\CarePlan;
use App\Models\EhealthLink;
use App\Models\LegalEntity;
use App\Models\MedicalEvents\Sql\Approval;
use App\Models\User;
use App\Repositories\MedicalEvents\Repository;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

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

        if (empty($payload['authorize_with'])) {
            unset($payload['authorize_with']);
        }

        return $payload;
    }

    public function resolveAccessLevel(CarePlan $carePlan, ?LegalEntity $legalEntity = null): string
    {
        $legalEntity ??= legalEntity();

        return (int) $carePlan->legalEntityId === (int) $legalEntity?->id ? 'write' : 'read';
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
        $legalEntity ??= legalEntity();

        $payload = $this->buildCreatePayload($carePlan, $employeeUuid, $accessLevel, $authorizeWith);
        $response = EHealth::approval()->createApproval($patientUuid, $payload);
        $responseData = $response->getData();
        $statusCode = $response->getStatusCode();

        if ($statusCode === 202) {
            return $this->handleAsyncCreate($carePlan, $responseData, $legalEntity, $user, $bearerToken, $employeeUuid);
        }

        if (!in_array($statusCode, [200, 201], true)) {
            throw new \RuntimeException('Unexpected eHealth approval create status: '.$statusCode);
        }

        $approvalId = $this->extractApprovalId($responseData);
        $authMethod = $this->extractAuthMethod($responseData);
        $urgentOtp = ($authMethod['type'] ?? null) === 'OTP';

        if (($authorizeWith || $urgentOtp) && $approvalId) {
            return new CarePlanApprovalCreateResult(
                CarePlanApprovalCreateOutcome::OtpRequired,
                $approvalId,
                null,
                $authMethod,
            );
        }

        return new CarePlanApprovalCreateResult(
            CarePlanApprovalCreateOutcome::Granted,
            $approvalId,
        );
    }

    public function verify(string $patientUuid, string $approvalId, int $code): EHealthResponse
    {
        return EHealth::approval()->verify($patientUuid, $approvalId, [
            'code' => $code,
        ]);
    }

    public function deactivate(string $patientUuid, string $approvalId): EHealthResponse
    {
        return EHealth::approval()->verify($patientUuid, $approvalId, [
            'status' => 'inactive',
        ]);
    }

    public function resendSms(string $patientUuid, string $approvalId): EHealthResponse
    {
        $key = 'care-plan-otp-resend:'.$patientUuid.':'.$approvalId;

        if (!\Illuminate\Support\Facades\Cache::add($key, true, now()->addMinutes(10))) {
            throw new \RuntimeException(__('validation.sms_already_resent'));
        }

        return EHealth::approval()->resendSms($patientUuid, $approvalId);
    }

    public function syncForCarePlan(CarePlan $carePlan): void
    {
        // Uses Get approvals filters (granted_resource_type + granted_resources) via syncApprovals.
        Repository::approval()->syncApprovals($carePlan, 'care_plan', []);
    }

    /**
     * Resolve Livewire poll status for an async create job.
     */
    public function resolveAsyncJob(int $pollingLinkId): CarePlanApprovalJobStatusResult
    {
        $link = EhealthLink::with(['job', 'linkable', 'processingData'])->find($pollingLinkId);

        if (!$link || !$link->job) {
            return new CarePlanApprovalJobStatusResult(CarePlanApprovalJobOutcome::Pending);
        }

        $status = strtoupper((string) ($link->job->status ?? ''));
        $jobResult = $link->processingData->sortByDesc('id')->first()?->response_data ?? $link->job->response_data ?? [];

        if (is_string($jobResult)) {
            $jobResult = json_decode($jobResult, true) ?? [];
        }

        if ($status === 'FAILED') {
            return new CarePlanApprovalJobStatusResult(
                CarePlanApprovalJobOutcome::Failed,
                errorMessage: $this->formatJobError($jobResult),
            );
        }

        if ($status !== 'PROCESSED') {
            return new CarePlanApprovalJobStatusResult(CarePlanApprovalJobOutcome::Pending);
        }

        $realApprovalId = $this->extractApprovalId($jobResult);

        if ($realApprovalId && $link->linkable instanceof Approval) {
            Log::info('CarePlanApprovalService: swapping provisional UUID to real ESOZ approval UUID', [
                'old_uuid' => $link->linkable->uuid,
                'new_uuid' => $realApprovalId,
                'linkable_id' => $link->linkable->id,
            ]);
            $link->linkable->update(['uuid' => $realApprovalId]);
        }

        $authMethod = $this->extractAuthMethod($jobResult);
        $isVerified = $this->extractIsVerified($jobResult);

        if ($isVerified === true) {
            return new CarePlanApprovalJobStatusResult(
                CarePlanApprovalJobOutcome::Granted,
                $realApprovalId,
            );
        }

        if ($isVerified === false) {
            return new CarePlanApprovalJobStatusResult(
                CarePlanApprovalJobOutcome::OtpRequired,
                $realApprovalId,
                $authMethod,
            );
        }

        $authType = is_array($authMethod) ? ($authMethod['type'] ?? null) : null;
        if (in_array($authType, ['OFFLINE', 'THIRD_PERSON'], true)) {
            return new CarePlanApprovalJobStatusResult(
                CarePlanApprovalJobOutcome::Granted,
                $realApprovalId,
            );
        }

        return new CarePlanApprovalJobStatusResult(CarePlanApprovalJobOutcome::Pending);
    }

    /**
     * @param  array<string, mixed>  $responseData
     */
    private function handleAsyncCreate(
        CarePlan $carePlan,
        array $responseData,
        ?LegalEntity $legalEntity,
        ?User $user,
        ?string $bearerToken,
        ?string $grantedToEmployeeUuid = null,
    ): CarePlanApprovalCreateResult {
        $href = $responseData['links'][0]['href'] ?? null;

        if (!$href) {
            throw new \RuntimeException('Async approval response missing job link href');
        }

        if (!$legalEntity) {
            throw new \RuntimeException('Legal entity is required for async approval processing');
        }

        if (!$user) {
            throw new \RuntimeException('User is required for async approval processing');
        }

        if ($bearerToken === null || $bearerToken === '') {
            throw new \RuntimeException('Bearer token is required for async approval processing');
        }

        $approvalUuid = $responseData['id'] ?? (string) Str::uuid();

        $attributes = [
            'approvable_type' => CarePlan::class,
            'approvable_id' => $carePlan->id,
            'status' => ApprovalStatus::PENDING->value,
        ];

        if ($grantedToEmployeeUuid) {
            $identifier = Repository::identifier()->store($grantedToEmployeeUuid);
            $attributes['granted_to_id'] = $identifier->id;
            $attributes['granted_to_type'] = 'employee';
        }

        $localApproval = Approval::firstOrCreate(
            ['uuid' => $approvalUuid],
            $attributes
        );

        $link = Repository::approval()->attachEhealthLink($localApproval, ['href' => $href]);

        Bus::batch([
            new RemoteEHealthLinksProcessing(
                eHealthLink: $link,
                legalEntity: $legalEntity,
                standalone: true
            ),
        ])
            ->withOption('legal_entity_id', $legalEntity->id)
            ->withOption('token', Crypt::encryptString($bearerToken))
            ->withOption('user', $user)
            ->name(RemoteEHealthLinksProcessing::BATCH_NAME)
            ->onQueue('sync')
            ->dispatch();

        return new CarePlanApprovalCreateResult(
            CarePlanApprovalCreateOutcome::Async,
            $localApproval->uuid,
            $link->id,
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function extractApprovalId(array $data): ?string
    {
        $id = $data['response_data']['id']
            ?? $data['data']['id']
            ?? $data['id']
            ?? null;

        return is_string($id) || is_numeric($id) ? (string) $id : null;
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

        return is_array($method) ? $method : null;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function extractIsVerified(array $data): ?bool
    {
        $candidates = [
            $data['response']['body']['data']['is_verified'] ?? null,
            $data['response_data']['is_verified'] ?? null,
            $data['data']['is_verified'] ?? null,
            $data['is_verified'] ?? null,
            $data['urgent']['is_verified'] ?? null,
        ];

        foreach ($candidates as $value) {
            if (is_bool($value)) {
                return $value;
            }

            if ($value === 0 || $value === 1 || $value === '0' || $value === '1') {
                return (bool) $value;
            }

            if ($value === 'true' || $value === 'false') {
                return $value === 'true';
            }
        }

        return null;
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
