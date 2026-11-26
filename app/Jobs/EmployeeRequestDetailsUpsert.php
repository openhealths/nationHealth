<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Exceptions\EHealth\EHealthConnectionException;
use Throwable;
use App\Core\Arr;
use Carbon\Carbon;
use App\Models\User;
use App\Core\EHealthJob;
use App\Enums\JobStatus;
use App\Models\LegalEntity;
use App\Repositories\Repository;
use App\Classes\eHealth\EHealth;
use Illuminate\Support\Facades\Log;
use Illuminate\Queue\SerializesModels;
use App\Classes\eHealth\EHealthResponse;
use App\Enums\Employee\RequestStatus;
use App\Enums\Employee\RevisionStatus;
use App\Models\Employee\EmployeeRequest;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\Middleware\RateLimited;

class EmployeeRequestDetailsUpsert extends EHealthJob
{
    use Dispatchable;
    use SerializesModels;

    public const string BATCH_NAME = 'EmployeeRequestDetailsSync';

    public const string SCOPE_REQUIRED = 'employee_request:read';

    public const string ENTITY = LegalEntity::ENTITY_EMPLOYEE_REQUEST;

    protected const int RATE_LIMIT_DELAY = 3;

    public function __construct(
        public EmployeeRequest $employeeRequest,
        public ?LegalEntity $legalEntity,
        protected ?EHealthJob $nextEntity = null,
        public bool $standalone = false,
    ) {
        parent::__construct(legalEntity: $legalEntity, nextEntity: $nextEntity, standalone: $standalone);
    }

    /**
     * Get data from EHealth API
     *
     * @throws EHealthConnectionException
     */
    protected function sendRequest(string $token): PromiseInterface|EHealthResponse|null
    {
        return EHealth::employeeRequest()->withToken($token)->getDetails($this->employeeRequest->uuid);
    }

    /**
     * Store or update data in the database
     *
     * @throws Throwable
     */
    protected function processResponse(?EHealthResponse $response): void
    {
        $validatedData = $response->validate();
        $remoteStatus = $validatedData['status'];
        $revision = $this->employeeRequest->revision;

        // Leave an approved submission retryable until the processor applies its local revision.
        if ($remoteStatus === RequestStatus::APPROVED->value && $this->employeeRequest->isPendingEhealth() && $revision) {
            return;
        }

        $validatedData['inserted_at'] = Carbon::parse($validatedData['inserted_at'])->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s');

        Log::info('Processing EmployeeRequestDetailsUpsert for employee_request:' . $this->employeeRequest->id . ', LE:' . ($this->legalEntity->id ?? 'N/A'));

        $this->employeeRequest->legalEntityUuid = $this->legalEntity?->uuid;

        $userEmail = Arr::get($validatedData, 'party.email');

        $employeeRequestUser = User::where('email', $userEmail)->first();

        $employeeRequestPartyId = $this->employeeRequest->partyId ?? $employeeRequestUser?->partyId;

        $isTerminal = in_array($remoteStatus, [RequestStatus::APPROVED->value, RequestStatus::REJECTED->value, RequestStatus::EXPIRED->value], true);

        $fillData = array_merge(
            $response->map($validatedData, $this->legalEntity, $employeeRequestUser?->id, $employeeRequestPartyId),
            [
                'sync_status' => JobStatus::COMPLETED->value,
                'inserted_at' => Carbon::parse($validatedData['inserted_at'])->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s') ?? Carbon::now(),
            ]
        );

        // Only terminal eHealth decisions get applied_at — not details refresh of still-NEW rows.
        if ($isTerminal) {
            $fillData['applied_at'] = Carbon::parse($validatedData['updated_at'] ?? now())
                ->setTimezone(config('app.timezone'))
                ->format('Y-m-d H:i:s');
        }

        $this->employeeRequest->fill($fillData);

        $this->employeeRequest->save();

        $revisionData['data'] = EHealth::employeeRequest()->mapRevisionData($response);
        if ($employeeUuid = data_get($revision?->data, 'employee_uuid')) {
            $revisionData['data']['employee_uuid'] = $employeeUuid;
        }
        $revisionData['ehealth_response'] = [ 'data' => $response->getData()];
        $revisionData['status'] = match ($remoteStatus) {
            RequestStatus::APPROVED->value => $revision?->status === RevisionStatus::OUTDATED
                ? RevisionStatus::OUTDATED
                : RevisionStatus::APPLIED,
            RequestStatus::REJECTED->value, RequestStatus::EXPIRED->value => RevisionStatus::OUTDATED,
            default => RevisionStatus::SENT,
        };

        Repository::revision()->saveRevision($this->employeeRequest, $revisionData);
    }

    /**
     * Get additional middleware configurations for the job.
     *
     * @return array Returns an array of middleware configurations to be applied to the job
     */
    protected function getAdditionalMiddleware(): array
    {
        return [
            new RateLimited('ehealth-employee-request-get')
        ];
    }

    // Get next entity job if needed
    protected function getNextEntityJob(): ?EHealthJob
    {
        return $this->standalone || !$this->nextEntity
            ? new CompleteSync($this->legalEntity, isFirstLogin: $this->isFirstLogin)
            : $this->nextEntity;
    }
}
