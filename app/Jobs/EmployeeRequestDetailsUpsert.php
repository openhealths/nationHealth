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
use App\Enums\Employee\RevisionStatus;
use App\Models\Employee\EmployeeRequest;
use App\Services\Employee\EmployeeRequestProcessor;
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
        public EmployeeRequest|string $employeeRequest,
        public ?LegalEntity $legalEntity,
        protected ?EHealthJob $nextEntity = null,
        public bool $standalone = false,
        protected bool $isFirstLogin = false,
    ) {
        $this->employeeRequest = $employeeRequest instanceof EmployeeRequest ? $employeeRequest->uuid : $employeeRequest;
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
            return EHealth::employeeRequest()->withToken($token)->getDetails($this->requestUuid());
        } catch (\App\Exceptions\EHealth\EHealthResponseException $exception) {
            if (!in_array($exception->response->status(), [403, 404], true)) {
                throw $exception;
            }

            Log::warning('Employee request details are unavailable in the current legal entity.', ['legalEntityId' => $this->legalEntity->id, 'status' => $exception->response->status()]);

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
        $requestUuid = $this->requestUuid();

        if (strtolower($validatedData['legal_entity_uuid'] ?? '') !== strtolower($this->legalEntity->uuid)
            || ($validatedData['uuid'] ?? null) !== $requestUuid) {
            Log::warning('Rejected employee request from another legal entity.', ['legalEntityId' => $this->legalEntity->id]);

            return;
        }

        $request = EmployeeRequest::firstOrNew(['uuid' => $requestUuid]);

        if ($request->exists && (int) $request->legalEntityId !== (int) $this->legalEntity->id) {
            Log::warning('Rejected employee request stored in another legal entity.', ['requestId' => $request->id, 'legalEntityId' => $this->legalEntity->id]);

            return;
        }

        $validatedData['inserted_at'] = Carbon::parse($validatedData['inserted_at'])->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s');

        Log::info('Processing EmployeeRequestDetailsUpsert.', ['requestId' => $request->id, 'legalEntityId' => $this->legalEntity->id]);

        $userEmail = Arr::get($validatedData, 'party.email');

        $employeeRequestUser = User::where('email', $userEmail)
            ->whereHas('employees', fn ($employees) => $employees->whereLegalEntityId($this->legalEntity->id))
            ->first();

        $employeeRequestPartyId = $employeeRequestUser?->partyId;

        $remoteStatus = $validatedData['status'] ?? null;
        $isTerminal = in_array($remoteStatus, ['APPROVED', 'REJECTED', 'EXPIRED'], true);

        $fillData = array_merge(
            $response->map($validatedData, $this->legalEntity, $employeeRequestUser?->id ?? null, $employeeRequestPartyId ?? null),
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
        $revisionData['status'] = in_array($remoteStatus, ['REJECTED', 'EXPIRED'], true)
            ? RevisionStatus::OUTDATED->value
            : ($remoteStatus === 'APPROVED' ? RevisionStatus::APPLIED->value : RevisionStatus::PENDING->value);

        \Illuminate\Support\Facades\DB::transaction(function () use ($request, $validatedData, $remoteStatus, $fillData, $revisionData) {
            if ($remoteStatus === 'APPROVED' && $request->exists && $request->isPendingEhealth() && $request->revision) {
                app(EmployeeRequestProcessor::class)->applyApprovedRequest($request, $validatedData);
            }

            $fillData['user_id'] ??= $request->userId;
            $fillData['party_id'] ??= $request->partyId;
            $request->fill($fillData)->save();
            Repository::revision()->saveRevision($request, $revisionData);
        });
    }

    private function requestUuid(): string
    {
        // Jobs queued before this change can still contain a serialized model.
        return $this->employeeRequest instanceof EmployeeRequest ? $this->employeeRequest->uuid : $this->employeeRequest;
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
