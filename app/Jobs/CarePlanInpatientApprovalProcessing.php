<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Classes\eHealth\EHealthResponse;

use App\Core\EHealthJob;
use App\Enums\JobStatus;
use App\Models\EhealthLink;

use App\Models\LegalEntity;
use App\Notifications\RemoteEHealthLinksNotification;
use App\Repositories\Repository;
use App\Services\MedicalEvents\CarePlanApprovalService;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Support\Facades\Log;
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
        $service = app(CarePlanApprovalService::class);
        if (!$plan || !$service->skipsPatientOtp($plan, $this->legalEntity)
            || !in_array($plan->status, ['new', 'active'], true)) {
            $repository->setInpatientApprovalProgress($this->submission, 'skipped');

            return null;
        }
        $result = $service->create(
            carePlan: $plan,
            patientUuid: $plan->person->uuid,
            employeeUuid: $this->employeeUuid ?? $plan->authorReference->value,
            accessLevel: 'write',
            legalEntity: $this->legalEntity,
            user: $this->user,
            bearerToken: $token,
        );
        if (!$result->isGranted() && !$result->isAsync()) {
            throw new RuntimeException(__('care-plan.approval_verify_error'));
        }
        $repository->setInpatientApprovalProgress(
            $this->submission,
            $result->isGranted() ? 'granted' : 'pending',
            $result->pollingLinkId,
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
}
