<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Classes\eHealth\Api\Contract as ApiContract;
use App\Classes\eHealth\EHealthResponse;
use App\Core\EHealthJob;
use App\Enums\JobStatus;
use App\Jobs\CompleteSync as CompleteSyncJob;
use App\Models\Contracts\Contract;
use App\Models\LegalEntity;
use App\Repositories\Repository;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Queue\Middleware\RateLimited;
use Throwable;

class ContractDetailsUpsert extends EHealthJob
{
    public const string BATCH_NAME = 'ContractDetailsSync';
    public const string SCOPE_REQUIRED = 'contract:read';
    public const string ENTITY = LegalEntity::ENTITY_CONTRACT;

    public function __construct(
        public Contract $contractModel,
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
        $contractType = strtolower($this->contractModel->type);

        return app(ApiContract::class)
            ->withToken($token)
            ->getDetails($this->contractModel->uuid, $contractType);
    }

    /**
     * Update the database with rich nested data and mark as COMPLETED
     */
    protected function processResponse(?EHealthResponse $response): void
    {
        $ehealthData = $response->validate();
        $ehealthData['sync_status'] = JobStatus::COMPLETED->value;
        Repository::contract()->saveFromEHealth($ehealthData, $this->legalEntity, details: true);
    }

    public function failed(?Throwable $exception): void
    {
        $this->contractModel->update(['sync_status' => JobStatus::FAILED]);
        parent::failed($exception);
    }

    protected function getAdditionalMiddleware(): array
    {
        return [
            new RateLimited('ehealth-contract-get')
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
