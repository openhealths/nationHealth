<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Classes\eHealth\Api\ContractRequest as ApiContractRequest;
use App\Classes\eHealth\EHealthResponse;
use App\Core\EHealthJob;
use App\Enums\JobStatus;
use App\Jobs\CompleteSync as CompleteSyncJob;
use App\Models\Contracts\ContractRequest;
use App\Models\LegalEntity;
use App\Repositories\Repository;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Queue\Middleware\RateLimited;
use Throwable;

class ContractRequestDetailsUpsert extends EHealthJob
{
    public const string BATCH_NAME = 'ContractRequestDetailsSync';
    public const string SCOPE_REQUIRED = 'contract_request:read';
    public const string ENTITY = LegalEntity::ENTITY_CONTRACT_REQUEST;

    public function __construct(
        public ContractRequest $contractRequestModel,
        public ?LegalEntity $legalEntity,
        protected ?EHealthJob $nextEntity = null,
        public bool $standalone = false,
    ) {
        parent::__construct(legalEntity: $legalEntity, nextEntity: $nextEntity, standalone: $standalone);
    }

    /**
     * Fetch detailed data from eHealth API
     */
    protected function sendRequest(string $token): PromiseInterface|EHealthResponse
    {
        $contractType = strtolower($this->contractRequestModel->type);

        return app(ApiContractRequest::class)
            ->withToken($token)
            ->getDetails($contractType, $this->contractRequestModel->uuid);
    }

    /**
     * Update the database with rich nested data and mark as COMPLETED
     */
    protected function processResponse(?EHealthResponse $response): void
    {
        $ehealthData = $response->validate();
        $ehealthData['sync_status'] = JobStatus::COMPLETED->value;
        Repository::contractRequest()->saveFromEHealth($ehealthData, $this->contractRequestModel->type, $this->legalEntity, details: true);
    }

    public function failed(?Throwable $exception): void
    {
        $this->contractRequestModel->update(['sync_status' => JobStatus::FAILED]);
        parent::failed($exception);
    }

    protected function getAdditionalMiddleware(): array
    {
        return [
            new RateLimited('ehealth-contract-request-get')
        ];
    }

    /**
     * Chain logic: continue to the next item or finish
     */
    protected function getNextEntityJob(): ?EHealthJob
    {
        return $this->standalone || !$this->nextEntity
            ? new CompleteSyncJob($this->legalEntity, isFirstLogin: $this->isFirstLogin)
            : $this->nextEntity;
    }
}
