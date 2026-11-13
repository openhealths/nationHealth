<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Classes\eHealth\Api\Contract as ApiContract;
use App\Classes\eHealth\EHealthResponse;
use App\Core\EHealthJob;
use App\Enums\JobStatus;
use App\Jobs\CompleteSync as CompleteSyncJob;
use App\Jobs\ContractDetailsUpsert as ContractDetailsUpsertJob;
use App\Jobs\ContractRequestSync as ContractRequestSyncJob;
use App\Models\Contracts\Contract;
use App\Models\LegalEntity;
use App\Repositories\Repository;
use App\Traits\BatchLegalEntityQueries;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Queue\Middleware\RateLimited;

class ContractSync extends EHealthJob
{
    use BatchLegalEntityQueries;

    public const string BATCH_NAME = 'ContractSync';
    public const string SCOPE_REQUIRED = 'contract:read';
    public const string ENTITY = LegalEntity::ENTITY_CONTRACT;

    // Jobs queued before typed synchronization was added have no serialized contractType property.
    protected string $contractType = 'CAPITATION';

    public function __construct(
        ?LegalEntity $legalEntity = null,
        ?EHealthJob $nextEntity = null,
        bool $isFirstLogin = false,
        int $page = 1,
        bool $standalone = false,
        string $contractType = 'CAPITATION',
    ) {
        parent::__construct($legalEntity, $nextEntity, $isFirstLogin, $page, $standalone);
        $this->contractType = $contractType;
    }

    protected function nextPageJob(): static
    {
        return new static($this->legalEntity, $this->nextEntity, $this->isFirstLogin, $this->page + 1, $this->standalone, $this->contractType);
    }

    /**
     * Get data from EHealth API (Contracts) using the API class directly via IOC container.
     */
    protected function sendRequest(string $token): PromiseInterface|EHealthResponse
    {
        // We use app() to resolve the API client directly, avoiding static Facade calls
        // and aliases to prevent conflict with Contract Model.
        return app(ApiContract::class)
            ->withToken($token)
            ->getMany(['contractor_legal_entity_id' => $this->legalEntity->uuid, 'page' => $this->page], $this->contractType);
    }

    /**
     * Store or update data in the database.
     */
    protected function processResponse(?EHealthResponse $response): void
    {
        $contracts = $response->validate();

        if (!empty($contracts)) {
            foreach ($contracts as $item) {
                $item['sync_status'] = JobStatus::PARTIAL->value;
                Repository::contract()->saveFromEHealth($item, $this->legalEntity);
            }
        }
    }

    protected function getAdditionalMiddleware(): array
    {
        return [
            new RateLimited('ehealth-contract-get')
        ];
    }

    /**
     * Chain logic: After Contracts are synced, start the ContractRequestSync chain.
     * We start with CAPITATION type.
     */
    protected function getNextEntityJob(): ?EHealthJob
    {
        if ($this->contractType === 'CAPITATION') {
            return new self($this->legalEntity, $this->nextEntity, $this->isFirstLogin, standalone: $this->standalone, contractType: 'REIMBURSEMENT');
        }

        $nextJob = $this->standalone
            ? new CompleteSyncJob($this->legalEntity, isFirstLogin: $this->isFirstLogin)
            : new ContractRequestSyncJob($this->legalEntity, $this->nextEntity, $this->isFirstLogin, $this->user, 'CAPITATION');

        // The summary does not include status_reason. Fetch details before confirming completion.
        $contracts = Contract::query()->forLegalEntity($this->legalEntity)
            ->where('sync_status', JobStatus::PARTIAL->value)->get();
        foreach ($contracts as $contract) {
            $nextJob = new ContractDetailsUpsertJob($contract, $this->legalEntity, $nextJob);
        }

        return $nextJob;
    }
}
