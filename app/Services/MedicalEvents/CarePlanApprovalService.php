<?php

declare(strict_types=1);

namespace App\Services\MedicalEvents;

use App\Classes\eHealth\EHealth;
use App\Classes\eHealth\EHealthResponse;
use App\Jobs\RemoteEHealthLinksProcessing;
use App\Models\CarePlan;
use App\Models\EhealthLink;
use App\Models\LegalEntity;
use App\Models\MedicalEvents\Sql\Approval;
use App\Models\User;
use App\Repositories\MedicalEvents\Repository;
use Illuminate\Support\Facades\Auth;
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

        return $carePlan->legal_entity_id === $legalEntity?->id ? 'write' : 'read';
    }

    /**
     * Create a care_plan approval in eHealth and persist local Approval / async job state.
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
    ): CarePlanApprovalCreateResult {
        $legalEntity ??= legalEntity();
        $user ??= Auth::user();

        $payload = $this->buildCreatePayload($carePlan, $employeeUuid, $accessLevel, $authorizeWith);
        $response = EHealth::approval()->createApproval($patientUuid, $payload);
        $responseData = $response->getData();
        $statusCode = $response->getStatusCode();

        if ($statusCode === 202) {
            return $this->handleAsyncCreate($carePlan, $responseData, $legalEntity, $user, $employeeUuid);
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

    public function resendSms(string $patientUuid, string $approvalId): EHealthResponse
    {
        return EHealth::approval()->resendSms($patientUuid, $approvalId);
    }

    public function syncForCarePlan(CarePlan $carePlan): void
    {
        $employeeUuid = \Illuminate\Support\Facades\Auth::user()?->activeDoctorEmployee()?->uuid;
        $filters = [];
        if ($employeeUuid) {
            $filters['granted_to.identifier.value'] = $employeeUuid;
        }

        // Uses Get approvals filters (granted_resource_type + granted_resources) via syncApprovals.
        Repository::approval()->syncApprovals($carePlan, 'care_plan', $filters);
    }

    /**
     * Resolve Livewire poll status for an async create job.
     */
    public function resolveAsyncJob(int $pollingLinkId): CarePlanApprovalJobStatusResult
    {
        $link = EhealthLink::with(['job', 'linkable'])->find($pollingLinkId);

        if (!$link || !$link->job) {
            return new CarePlanApprovalJobStatusResult(CarePlanApprovalJobOutcome::Pending);
        }

        $status = strtoupper((string) ($link->job->status ?? ''));

        if ($status === 'PENDING') {
            try {
                $jobUuid = basename($link->href);
                $response = EHealth::job()->getDetails($jobUuid);
                $responseData = $response->getData();

                $newStatus = strtoupper($responseData['status'] ?? 'PENDING');
                if ($newStatus !== 'PENDING') {
                    $link->job()->update(['status' => $newStatus, 'response_data' => $responseData]);
                    $link->update(['status' => $newStatus]);
                    $status = $newStatus;
                    $link->setRelation('job', $link->job->refresh());
                }
            } catch (\Exception $e) {
                Log::warning('CarePlanApprovalService: Failed to fetch job details from eHealth during polling: ' . $e->getMessage());
            }
        }

        if ($status === 'FAILED') {
            return new CarePlanApprovalJobStatusResult(
                CarePlanApprovalJobOutcome::Failed,
                errorMessage: $this->formatJobError($link->job->response_data ?? []),
            );
        }

        if ($status !== 'PROCESSED') {
            return new CarePlanApprovalJobStatusResult(CarePlanApprovalJobOutcome::Pending);
        }

        $jobResult = $link->job->response_data ?? [];
        $realApprovalId = $this->extractApprovalId($jobResult);

        if ($realApprovalId && $link->linkable instanceof Approval) {
            Log::info('CarePlanApprovalService: swapping provisional UUID to real ESOZ approval UUID', [
                'old_uuid' => $link->linkable->uuid,
                'new_uuid' => $realApprovalId,
                'linkable_id' => $link->linkable->id,
            ]);
            $link->linkable->update(['uuid' => $realApprovalId]);
        }

        $isVerified = $jobResult['response']['body']['data']['is_verified']
            ?? $jobResult['response_data']['is_verified']
            ?? $jobResult['data']['is_verified']
            ?? $jobResult['is_verified']
            ?? $jobResult['urgent']['is_verified']
            ?? true;

        if (!$isVerified) {
            return new CarePlanApprovalJobStatusResult(
                CarePlanApprovalJobOutcome::OtpRequired,
                $realApprovalId,
                $this->extractAuthMethod($jobResult),
            );
        }

        return new CarePlanApprovalJobStatusResult(
            CarePlanApprovalJobOutcome::Granted,
            $realApprovalId,
        );
    }

    /**
     * @param  array<string, mixed>  $responseData
     */
    private function handleAsyncCreate(
        CarePlan $carePlan,
        array $responseData,
        ?LegalEntity $legalEntity,
        ?User $user,
        ?string $grantedToEmployeeUuid = null,
    ): CarePlanApprovalCreateResult {
        $href = $responseData['links'][0]['href'] ?? null;

        if (!$href) {
            throw new \RuntimeException('Async approval response missing job link href');
        }

        if (!$legalEntity) {
            throw new \RuntimeException('Legal entity is required for async approval processing');
        }

        $approvalUuid = $responseData['id'] ?? (string) Str::uuid();

        $attributes = [
            'approvable_type' => CarePlan::class,
            'approvable_id' => $carePlan->id,
            'status' => 'NEW',
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

        $token = session()->get(config('ehealth.api.oauth.bearer_token'));

        Bus::batch([
            new RemoteEHealthLinksProcessing(
                eHealthLink: $link,
                legalEntity: $legalEntity,
                standalone: true
            ),
        ])
            ->withOption('legal_entity_id', $legalEntity->id)
            ->withOption('token', Crypt::encryptString((string) $token))
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

    private function extractAuthMethod(array $data): ?array
    {
        $method = $data['response']['body']['data']['authentication_method_current']
            ?? $data['response_data']['authentication_method_current']
            ?? $data['data']['authentication_method_current']
            ?? $data['authentication_method_current']
            ?? $data['urgent']['authentication_method_current']
            ?? null;

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
