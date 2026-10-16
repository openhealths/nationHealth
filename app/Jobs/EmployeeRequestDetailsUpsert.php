<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Exceptions\EHealth\EHealthConnectionException;
use App\Exceptions\EHealth\EHealthResponseException;
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
use App\Enums\Employee\RevisionStatus;
use App\Models\Employee\EmployeeRequest;
use App\Services\Employee\EmployeeRequestProcessor;
use Illuminate\Support\Facades\DB;
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
        protected bool $isFirstLogin = false,
    ) {
        parent::__construct(legalEntity: $legalEntity, nextEntity: $nextEntity, standalone: $standalone, isFirstLogin: $isFirstLogin);
    }

    /**
     * Get data from EHealth API
     *
     * @throws EHealthConnectionException
     */
    protected function sendRequest(string $token): PromiseInterface|EHealthResponse|null
    {
        try {
            return EHealth::employeeRequest()->withToken($token)->getDetails($this->employeeRequest->uuid);
        } catch (EHealthResponseException $exception) {
            if (!in_array($exception->response->status(), [403, 404], true)) {
                throw $exception;
            }
            $this->suspendUnassignedRequest();
            Log::warning('Employee request details are unavailable.', ['requestId' => $this->employeeRequest->id, 'status' => $exception->response->status()]);

            return null;
        }
    }

    /**
     * Store or update data in the database
     *
     * @throws Throwable
     */
    protected function processResponse(?EHealthResponse $response): void
    {
        if ($response === null) {
            return;
        }
        $validatedData = $response->validate();
        if (($validatedData['uuid'] ?? null) !== $this->employeeRequest->uuid) {
            $this->suspendUnassignedRequest();

            return;
        }
        $targetLegalEntity = LegalEntity::whereUuid($validatedData['legal_entity_uuid'])
            ->whereIsActive(true)->whereStatus('ACTIVE')->first();
        if ($targetLegalEntity === null) {
            $this->suspendUnassignedRequest();

            return;
        }

        $validatedData['inserted_at'] = Carbon::parse($validatedData['inserted_at'])->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s');

        Log::info('Processing EmployeeRequestDetailsUpsert for employee_request:' . $this->employeeRequest->id . ', LE:' . ($this->legalEntity->id ?? 'N/A'));

        $userEmail = Arr::get($validatedData, 'party.email');

        $employeeRequestUser = User::where('email', $userEmail)
            ->whereHas('employees', fn ($employees) => $employees->whereLegalEntityId($targetLegalEntity->id))
            ->first();

        $employeeRequestPartyId = $employeeRequestUser?->partyId;

        $remoteStatus = $validatedData['status'] ?? null;
        $isTerminal = in_array($remoteStatus, ['APPROVED', 'REJECTED', 'EXPIRED'], true);

        $fillData = array_merge(
            $response->map($validatedData, $targetLegalEntity, $employeeRequestUser?->id ?? null, $employeeRequestPartyId ?? null),
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

        $revisionData['data'] = Ehealth::employeeRequest()->mapRevisionData($response);
        $revisionData['ehealth_response'] = [ 'data' => $response->getData()];
        $revisionData['status'] = match ($remoteStatus) {
            'APPROVED' => RevisionStatus::APPLIED->value,
            'REJECTED', 'EXPIRED' => RevisionStatus::OUTDATED->value,
            default => RevisionStatus::PENDING->value,
        };
        DB::transaction(function () use ($targetLegalEntity, $validatedData, $remoteStatus, $fillData, $revisionData) {
            $request = EmployeeRequest::whereKey($this->employeeRequest->id)->lockForUpdate()->firstOrFail();
            if ($request->legalEntityId !== null && (int) $request->legalEntityId !== (int) $targetLegalEntity->id) {
                Log::warning('Employee request has conflicting ownership.', ['requestId' => $request->id]);

                return;
            }
            if ($remoteStatus === 'APPROVED' && $request->isPendingEhealth() && $request->revision) {
                app(EmployeeRequestProcessor::class)->applyApprovedRequest($request, $validatedData);
            }
            $fillData['user_id'] ??= $request->userId;
            $fillData['party_id'] ??= $request->partyId;
            $request->fill($fillData)->save();
            Repository::revision()->saveRevision($request, $revisionData);
        });
    }

    private function suspendUnassignedRequest(): void
    {
        EmployeeRequest::whereKey($this->employeeRequest->id)->whereNull('legal_entity_id')
            ->update(['sync_status' => JobStatus::SUSPENDED->value]);
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
