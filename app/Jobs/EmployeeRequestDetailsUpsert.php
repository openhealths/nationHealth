<?php

declare(strict_types=1);

namespace App\Jobs;

use Throwable;
use App\Core\Arr;
use App\Models\User;
use App\Core\EHealthJob;
use App\Enums\JobStatus;
use App\Models\LegalEntity;
use App\Repositories\Repository;
use App\Classes\eHealth\EHealth;
use Spatie\Permission\Models\Role;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Queue\SerializesModels;
use App\Classes\eHealth\EHealthResponse;
use App\Enums\Employee\RevisionStatus;
use App\Models\Employee\EmployeeRequest;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Http\Client\ConnectionException;

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
     * @throws ConnectionException
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

        Log::info('Processing EmployeeRequestDetailsUpsert for employee_request:' . $this->employeeRequest->id . ', LE:' . ($this->legalEntity->id ?? 'N/A'));

        $this->employeeRequest->legalEntityUuid = $this->legalEntity?->uuid;

        $userEmail = Arr::get($validatedData, 'party.email');

        $employeeRequestUser = User::where('email', $userEmail)->first();

        $employeeRequestPartyId = $employeeRequestUser?->partyId;

        $this->employeeRequest->fill(
            array_merge(
                $response->map($validatedData, $this->legalEntity, $employeeRequestUser?->id ?? null, $employeeRequestPartyId ?? null),
                ['sync_status' => JobStatus::COMPLETED->value]
            )
        );

        $this->employeeRequest->save();

        $revisionData['data'] = Ehealth::employeeRequest()->mapRevisionData($response);
        $revisionData['ehealth_response'] = [ 'data' => $response->getData()];
        $revisionData['status'] = RevisionStatus::APPLIED->value;

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

    /**
     * Determine which authentication guards define the given role.
     * Checks only the 'web' and 'ehealth' guards.
     * Queries Spatie\Permission\Models\Role by name and guard_name.
     * Returns an empty collection if the role is not defined for any of the checked guards.
     *
     * @param  string  $role  The role name to check across guards.
     * @return Collection<int, string> Collection of guard names that have this role defined.
     */
    protected function getGuardsForRole(string $role): Collection
    {
        $guards = collect(['web', 'ehealth']);

        return $guards->filter(
            fn ($guard) =>
                Role::where('name', $role)
                    ->where('guard_name', $guard)
                    ->exists()
        );
    }
}
