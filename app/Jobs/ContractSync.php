<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Classes\eHealth\Api\Contract as ApiContract;
use App\Classes\eHealth\EHealthResponse;
use App\Core\EHealthJob;
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

    /**
     * Get data from EHealth API (Contracts) using the API class directly via IOC container.
     */
    protected function sendRequest(string $token): PromiseInterface|EHealthResponse
    {
        // We use app() to resolve the API client directly, avoiding static Facade calls
        // and aliases to prevent conflict with Contract Model.
        return app(ApiContract::class)
            ->withToken($token)
            ->getMany(['contractor_legal_entity_id' => $this->legalEntity->uuid]);
    }

    /**
     * Store or update data in the database.
     */
    protected function processResponse(?EHealthResponse $response): void
    {
        $contracts = $response?->validate();

        if (!empty($contracts)) {
            foreach ($contracts as $item) {
                Repository::contract()->saveFromEHealth($item);
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
        return new ContractRequestSync(
            $this->legalEntity,
            $this->nextEntity, // Pass the original next entity to the end of the chain
            $this->isFirstLogin,
            $this->user,
            'CAPITATION' // Initial type
        );
    }
}
