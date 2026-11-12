<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Classes\eHealth\EHealth;
use App\Classes\eHealth\EHealthResponse;
use App\Core\EHealthJob;
use App\Enums\CarePlanApprovalCreateOutcome;
use App\Enums\CarePlanApprovalJobOutcome;
use App\Enums\JobStatus;
use App\Enums\Person\AuthenticationMethod;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Exceptions\EHealth\EHealthValidationException;
use App\Jobs\RemoteEHealthLinksProcessing;
use App\Models\CarePlan;
use App\Models\EhealthLink;
use App\Models\LegalEntity;
use App\Models\MedicalEvents\Sql\Approval as PatientApproval;
use App\Models\User;
use App\Notifications\RemoteEHealthLinksNotification;
use App\Repositories\MedicalEvents\Repository as MedicalEventsRepository;
use App\Repositories\Repository;
use App\Services\MedicalEvents\PatientApprovalService;
use Carbon\CarbonImmutable;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/** Grant the author access after a confirmed creation, without blocking the document page. */
class CarePlanInpatientApprovalProcessing extends EHealthJob
{
    public const string BATCH_NAME = 'CarePlanInpatientApprovalProcessing';

    public const string SCOPE_REQUIRED = 'approval:create';

    public function __construct(public EhealthLink $submission, LegalEntity $legalEntity, public ?string $employeeUuid = null)
    {
        parent::__construct(legalEntity: $legalEntity, standalone: true);
    }

    protected function sendRequest(string $token): PromiseInterface|EHealthResponse|null
    {
        $repository = Repository::carePlan();
        $plan = $repository->findById($this->submission->linkable_id);

        if (!$plan || !$plan->skipsPatientOtp($this->legalEntity)
            || !in_array($plan->status, ['new', 'active'], true)) {
            $repository->setInpatientApprovalProgress($this->submission, 'skipped');

            return null;
        }
        $result = $this->createCarePlanApproval(
            carePlan: $plan,
            patientUuid: $plan->person->uuid,
            employeeUuid: $this->employeeUuid ?? $plan->authorReference->value,
            accessLevel: 'write',
            legalEntity: $this->legalEntity,
            user: $this->user,
            bearerToken: $token,
        );
        if (!($result['outcome']->value === 'granted') && !($result['outcome']->value === 'async')) {
            throw new RuntimeException(__('care-plan.approval_verify_error'));
        }
        $repository->setInpatientApprovalProgress(
            $this->submission,
            ($result['outcome']->value === 'granted') ? 'granted' : 'pending',
            $result['pollingLinkId'],
        );

        return null;
    }

    protected function processResponse(?EHealthResponse $response): void
    {
    }

    protected function getAdditionalMiddleware(): array
    {
        return [];
    }

    protected function getNextEntityJob(): ?EHealthJob
    {
        return null;
    }

    protected function setEntityStatus(JobStatus $status): void
    {
    }

    public function failed(?Throwable $exception): void
    {
        Repository::carePlan()->setInpatientApprovalProgress($this->submission, 'failed');
        Log::warning('Automatic inpatient care plan approval failed', [
            'submissionId' => $this->submission->id,
            'exceptionType' => $exception ? $exception::class : null,
        ]);
        ($this->user ?? ($this->batch()?->options['user'] ?? null))?->notify(
            new RemoteEHealthLinksNotification(__('care-plan.inpatient_access_failed'), 'error')
        );
    }
    protected function buildCarePlanApprovalPayload(
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

        if ($carePlan->skipsPatientOtp($legalEntity) || empty($payload['authorize_with'])) {
            unset($payload['authorize_with']);
        }

        return $payload;
    }

    protected function createCarePlanApproval(
        CarePlan $carePlan,
        string $patientUuid,
        string $employeeUuid,
        string $accessLevel = 'write',
        ?string $authorizeWith = null,
        ?LegalEntity $legalEntity = null,
        ?User $user = null,
        ?string $bearerToken = null,
    ): array {
        Repository::carePlan()->assertApprovalCreationReady($carePlan);
        $legalEntity ??= legalEntity();
        if (!$legalEntity || !$user || !$bearerToken || $patientUuid !== $carePlan->person?->uuid) {
            throw new RuntimeException(__('care-plan.approval_create_error'));
        }
        $repository = MedicalEventsRepository::approval();
        Repository::carePlan()->assertApprovalRecipient($employeeUuid, $legalEntity->id);
        $confirmWithoutOtp = $carePlan->skipsPatientOtp($legalEntity);
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
                    return ['outcome' => CarePlanApprovalCreateOutcome::Granted, 'approvalId' => $existing->uuid, 'pollingLinkId' => null, 'authMethod' => null];
                }
                $existingLink = $existing->ehealthLinks->filter(fn ($candidate): bool => data_get($candidate->job?->request_data, 'operation') !== 'resendSms')->sortByDesc('id')->first();
                if ($existingLink !== null && !in_array($existingLink->status, ['PROCESSED', 'FAILED'], true)) {
                    return $this->carePlanApprovalResultForLink($existingLink->id);
                }
                try {
                    $data = $this->requireApprovalResponse(EHealth::approval()->withToken($bearerToken)->getApprovalDetails($patientUuid, $existing->uuid))->validate();
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
                if ($this->carePlanApprovalIsGranted($data)) {
                    return ['outcome' => CarePlanApprovalCreateOutcome::Granted, 'approvalId' => $existing->uuid, 'pollingLinkId' => null, 'authMethod' => null];
                }
                if (!$existing->getAttribute('is_verified') && $existing->expiresAt?->isFuture() === true
                    && ($confirmWithoutOtp || $authorizeWith === null || $existing->authorizeWith === $authorizeWith)) {
                    if ($confirmWithoutOtp) {
                        $this->confirmCarePlanApprovalWithoutOtp($patientUuid, $existing->uuid, $bearerToken);

                        return ['outcome' => CarePlanApprovalCreateOutcome::Granted, 'approvalId' => $existing->uuid, 'pollingLinkId' => null, 'authMethod' => null];
                    }

                    return $this->carePlanApprovalResultFromData($data, $patientUuid, $existing->authorizeWith);
                }
            }
            $payload = $this->buildCarePlanApprovalPayload($carePlan, $employeeUuid, $accessLevel, $authorizeWith, $legalEntity);
            $link = $repository->beginCarePlanRequest($carePlan, $payload, $confirmWithoutOtp);
            $response = $this->requireApprovalResponse(EHealth::approval()->withToken($bearerToken)->createApproval($patientUuid, $payload));
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

                return ['outcome' => CarePlanApprovalCreateOutcome::Async, 'approvalId' => null, 'pollingLinkId' => $link->id, 'authMethod' => null];
            }
            $repository->completeCarePlanRequest($link, $data);
            $creationConfirmed = true;
            if ($confirmWithoutOtp && empty($data['is_verified'])) {
                $this->confirmCarePlanApprovalWithoutOtp($patientUuid, $data['id'], $bearerToken);

                return ['outcome' => CarePlanApprovalCreateOutcome::Granted, 'approvalId' => $data['id'], 'pollingLinkId' => null, 'authMethod' => null];
            }

            return $this->carePlanApprovalResultFromData($data, $patientUuid, $authorizeWith);
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

    protected function carePlanApprovalResultForLink(int $linkId): array
    {
        $result = $this->resolveCarePlanApprovalJob($linkId);
        if (($result['outcome']->value === 'failed')) {
            throw new RuntimeException($result['errorMessage'] ?: __('care-plan.approval_result_unavailable'));
        }
        if (($result['outcome']->value === 'granted')) {
            return ['outcome' => CarePlanApprovalCreateOutcome::Granted, 'approvalId' => $result['approvalId'], 'pollingLinkId' => null, 'authMethod' => null];
        }
        if (in_array($result['outcome']->value, ['otp_required', 'offline_required'], true)) {
            return ['outcome' => ($result['outcome']->value === 'otp_required') ? CarePlanApprovalCreateOutcome::OtpRequired : CarePlanApprovalCreateOutcome::OfflineRequired, 'approvalId' => $result['approvalId'], 'pollingLinkId' => null, 'authMethod' => $result['authMethod']];
        }

        return ['outcome' => CarePlanApprovalCreateOutcome::Async, 'approvalId' => null, 'pollingLinkId' => $linkId, 'authMethod' => null];
    }

    protected function carePlanApprovalResultFromData(array $data, ?string $patientUuid = null, ?string $authorizeWith = null): array
    {
        if ($this->carePlanApprovalIsGranted($data)) {
            return ['outcome' => CarePlanApprovalCreateOutcome::Granted, 'approvalId' => $data['id'], 'pollingLinkId' => null, 'authMethod' => null];
        }
        if (($data['is_verified'] ?? null) !== false || empty($data['expires_at'])
            || !CarbonImmutable::parse($data['expires_at'])->isFuture()) {
            throw new RuntimeException(__('care-plan.approval_result_unavailable'));
        }
        $method = $this->extractCarePlanApprovalAuthMethod($data);
        $method = app(PatientApprovalService::class)->resolveConfirmationMethod($method ?? [], $patientUuid, $data['authorize_with'] ?? $authorizeWith);
        if (isset($data['id']) && AuthenticationMethod::confirmationMethod($method ?? []) !== null) {
            $outcome = AuthenticationMethod::confirmationMethod($method) === AuthenticationMethod::OTP
                ? CarePlanApprovalCreateOutcome::OtpRequired : CarePlanApprovalCreateOutcome::OfflineRequired;

            return ['outcome' => $outcome, 'approvalId' => $data['id'], 'pollingLinkId' => null, 'authMethod' => $method];
        }

        throw new RuntimeException(__('care-plan.approval_result_unavailable'));
    }

    protected function carePlanApprovalIsGranted(array $data): bool
    {
        return ($data['is_verified'] ?? false) === true
            && !empty($data['expires_at'])
            && CarbonImmutable::parse($data['expires_at'])->isFuture();
    }

    protected function verifyCarePlanApproval(string $patientUuid, string $approvalId, ?int $code, ?string $bearerToken = null): EHealthResponse
    {
        $response = EHealth::approval()->verifyConfirmed($patientUuid, $approvalId, $code, $bearerToken);
        MedicalEventsRepository::approval()->storePatientResponse($patientUuid, $approvalId, $response->validate());

        return $response;
    }

    protected function confirmCarePlanApprovalWithoutOtp(string $patientUuid, string $approvalId, ?string $bearerToken = null): EHealthResponse
    {
        return $this->verifyCarePlanApproval($patientUuid, $approvalId, null, $bearerToken);
    }

    protected function requireApprovalResponse(EHealthResponse $response): EHealthResponse
    {
        if (!$response->successful()) {
            throw new EHealthResponseException($response);
        }

        return $response;
    }

    protected function resolveCarePlanApprovalJob(int $pollingLinkId): array
    {
        $link = MedicalEventsRepository::approval()->requestLink($pollingLinkId);
        if (!$link || !$link->job || !$link->linkable instanceof PatientApproval) {
            return ['outcome' => CarePlanApprovalJobOutcome::Failed, 'approvalId' => null, 'authMethod' => null, 'errorMessage' => __('care-plan.approval_result_unavailable')];
        }
        if (strtoupper((string) $link->job->status) === 'FAILED') {
            return ['outcome' => CarePlanApprovalJobOutcome::Failed, 'approvalId' => null, 'authMethod' => null, 'errorMessage' => $this->formatCarePlanApprovalJobError($link->job->response_data ?? [])];
        }
        $approval = $link->linkable;
        if ($approval->getAttribute('is_verified') && $approval->expiresAt?->isFuture() === true) {
            return ['outcome' => CarePlanApprovalJobOutcome::Granted, 'approvalId' => $approval->uuid, 'authMethod' => null, 'errorMessage' => null];
        }
        if ($approval->expiresAt !== null && !$approval->expiresAt->isFuture()) {
            return ['outcome' => CarePlanApprovalJobOutcome::Failed, 'approvalId' => null, 'authMethod' => null, 'errorMessage' => __('care-plan.approval_result_unavailable')];
        }
        if (!empty($link->job->response_data['confirmationError'])) {
            return ['outcome' => CarePlanApprovalJobOutcome::Failed, 'approvalId' => null, 'authMethod' => null, 'errorMessage' => __('care-plan.approval_verify_error')];
        }
        if (strtoupper((string) $link->job->status) !== 'PROCESSED') {
            return ['outcome' => CarePlanApprovalJobOutcome::Pending, 'approvalId' => null, 'authMethod' => null, 'errorMessage' => null];
        }
        try {
            $data = EHealth::approval()->validateResourceData($link->job->response_data ?? []);
            if (($data['authentication_method_current'] ?? null) === null && $approval->approvable instanceof CarePlan
                && $approval->approvable->skipsPatientOtp()) {
                return ['outcome' => CarePlanApprovalJobOutcome::Pending, 'approvalId' => null, 'authMethod' => null, 'errorMessage' => null];
            }
            $authorizeWith = $approval->authorizeWith ?: data_get($link->job?->request_data, 'authorize_with');
            $result = $this->carePlanApprovalResultFromData($data, $approval->approvable?->person?->uuid, $authorizeWith);
        } catch (ValidationException) {
            return ['outcome' => CarePlanApprovalJobOutcome::Failed, 'approvalId' => null, 'authMethod' => null, 'errorMessage' => __('care-plan.approval_result_unavailable')];
        } catch (RuntimeException $exception) {
            return ['outcome' => CarePlanApprovalJobOutcome::Failed, 'approvalId' => null, 'authMethod' => null, 'errorMessage' => $exception->getMessage()];
        }

        return ['outcome' => ($result['outcome']->value === 'granted') ? CarePlanApprovalJobOutcome::Granted
                : (($result['outcome']->value === 'otp_required') ? CarePlanApprovalJobOutcome::OtpRequired : CarePlanApprovalJobOutcome::OfflineRequired), 'approvalId' => $result['approvalId'], 'authMethod' => $result['authMethod'], 'errorMessage' => null];
    }

    protected function extractCarePlanApprovalAuthMethod(array $data): ?array
    {
        $method = $data['response_data']['authentication_method_current']
            ?? ($data['data']['authentication_method_current']
                ?? ($data['authentication_method_current']
                    ?? ($data['urgent']['authentication_method_current'] ?? null)));

        return is_array($method) ? $method : null;
    }

    protected function formatCarePlanApprovalJobError(array $jobResult): string
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
