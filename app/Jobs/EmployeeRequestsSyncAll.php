<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Classes\eHealth\EHealth;
use App\Classes\eHealth\EHealthResponse;
use App\Core\EHealthJob;
use App\Models\LegalEntity;
use App\Services\Employee\EmployeeRequestProcessor;
use App\Traits\BatchLegalEntityQueries;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Support\Facades\Log;

class EmployeeRequestsSyncAll extends EHealthJob
{
    use BatchLegalEntityQueries;

    public const string BATCH_NAME = 'EmployeeRequestsSyncAll';
    public const string SCOPE_REQUIRED = 'employee_request:read';
    public const string ENTITY = LegalEntity::ENTITY_EMPLOYEE_REQUEST;

    /** @var list<string> */
    protected array $requestUuids = [];

    public function handle(): void
    {
        if ($this->batch()?->processedJobs === 0) {
            $this->sendEntityNotification(static::ENTITY, 'started');
        }

        parent::handle();
    }

    protected function sendRequest(string $token): PromiseInterface|EHealthResponse
    {
        Log::info('[EmployeeRequestsSyncAll] Sending request for page ' . $this->page);

        return EHealth::employeeRequest()
            ->withToken($token)
            ->getMany(['edrpou' => $this->legalEntity->edrpou], $this->page);
    }

    protected function processResponse(?EHealthResponse $response): void
    {
        Log::info('[EmployeeRequestsSyncAll] Processing page ' . $this->page);

        $validatedData = $response?->validate() ?? [];

        // Use the service to process the data
        $processor = app(EmployeeRequestProcessor::class);
        $this->requestUuids = $processor->processBatch($validatedData, $this->legalEntity);
    }

    protected function getAdditionalMiddleware(): array
    {
        return [new RateLimited('ehealth-employee-request-get')];
    }

    /**
     * Get the next entity job to be scheduled after EmployeeRequestSync completes.
     *
     * If the job is standalone, returns a CompleteSync job for the current legal entity.
     * Verify this page's candidates before continuing to the next entity.
     *
     * @return EHealthJob|null
     */
    protected function getNextEntityJob(): ?EHealthJob
    {
        $nextEntity = $this->standalone || !$this->nextEntity
            ? new CompleteSync($this->legalEntity, isFirstLogin: $this->isFirstLogin)
            : $this->nextEntity;

        return $this->getRequestDetailsChain($nextEntity);
    }

    protected function getNextPageJob(): EHealthJob
    {
        return $this->getRequestDetailsChain(parent::getNextPageJob());
    }

    public function getRequestDetailsChain(?EHealthJob $nextEntity, ?array $requestUuids = null): ?EHealthJob
    {
        foreach (array_reverse($requestUuids ?? $this->requestUuids) as $uuid) {
            $nextEntity = new EmployeeRequestDetailsUpsert($uuid, $this->legalEntity, $nextEntity, isFirstLogin: $this->isFirstLogin);
        }

        return $nextEntity;
    }
}
