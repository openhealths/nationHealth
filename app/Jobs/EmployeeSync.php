<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Core\EHealthJob;
use App\Enums\JobStatus;
use App\Models\LegalEntity;
use App\Classes\eHealth\EHealth;
use App\Models\Employee\Employee;
use App\Traits\BatchLegalEntityQueries;
use GuzzleHttp\Promise\PromiseInterface;
use App\Classes\eHealth\EHealthResponse;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\PermissionRegistrar;

class EmployeeSync extends EHealthJob
{
    use BatchLegalEntityQueries;

    public const string BATCH_NAME = 'EmployeeFullSync';

    public const string SCOPE_REQUIRED = 'employee:read';

    public const string ENTITY = LegalEntity::ENTITY_EMPLOYEE;

    // Get data from EHealth API
    protected function sendRequest(string $token): PromiseInterface|EHealthResponse
    {
        return EHealth::employee()
            ->withToken($token)
            ->getMany(['legal_entity_id' => $this->legalEntity->uuid], $this->page);
    }

    /**
     * Store or update data in the database.
     *
     * * @param EHealthResponse|null $response
     */
    protected function processResponse(?EHealthResponse $response): void
    {
        if ($response) {
            Log::info('[EHealth Sync] Received Employee data structure:', [
                'legal_entity_id' => $this->legalEntity->id,
                'page' => $this->page,
                'data' => $response->json('data')
            ]);
        }

        $employees = $response->validate();

        data_forget($employees, '*.party');
        data_forget($employees, '*.doctor');
        data_fill($employees, '*.legal_entity_id', $this->legalEntity->id);
        data_fill($employees, '*.sync_status', JobStatus::PARTIAL->value);

        Employee::upsert($employees, uniqueBy: ['uuid']);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Get additional middleware configurations for the job.
     *
     * @return array Returns an array of middleware configurations to be applied to the job
     */
    protected function getAdditionalMiddleware(): array
    {
        return [
            new RateLimited('ehealth-employee-get')
        ];
    }

    /**
     * Get the next entity job to be scheduled after EmployeeSync completes.
     *
     * If the job is standalone, returns a CompleteSync job for the current legal entity.
     * Otherwise, returns a chain of EmployeeDetailsUpsert jobs for employees with PARTIAL sync status.
     *
     * @return EHealthJob|null
     */
    protected function getNextEntityJob(): ?EHealthJob
    {
        return $this->standalone
            ? new CompleteSync($this->legalEntity, isFirstLogin: $this->isFirstLogin)
            : $this->getEmployeeDetailsStartJob($this->legalEntity, $this->nextEntity);
    }
}
