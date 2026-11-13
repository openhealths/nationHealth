<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Classes\eHealth\Api\ContractRequest as ApiContractRequest;
use App\Classes\eHealth\EHealthResponse;
use App\Core\EHealthJob;
use App\Enums\JobStatus;
use App\Jobs\CompleteSync as CompleteSyncJob;
use App\Models\LegalEntity;
use App\Models\User;
use App\Repositories\Repository;
use App\Traits\BatchLegalEntityQueries;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Queue\Middleware\RateLimited;

class ContractRequestSync extends EHealthJob
{
    use BatchLegalEntityQueries;

    public const string BATCH_NAME = 'ContractRequestSync';
    public const string SCOPE_REQUIRED = 'contract_request:read';
    public const string ENTITY = LegalEntity::ENTITY_CONTRACT_REQUEST;

    protected string $contractType;

    /**
     * Override constructor to accept contractType.
     */
    public function __construct(
        LegalEntity $legalEntity,
        ?EHealthJob $nextEntity = null,
        bool $isFirstLogin = false,
        ?User $user = null,
        string $contractType = 'REIMBURSEMENT',
        int $page = 1,
        bool $standalone = false,
    ) {
        parent::__construct($legalEntity, $nextEntity, $isFirstLogin, $page, $standalone);

        // 2.Initialize our specific properties
        $this->contractType = $contractType;

        // 3. Manually assign a user, since the parent constructor does not accept it,
        // But we need it to get a token ($user property is in the parent class or third)
        if ($user) {
            $this->user = $user;
        }
    }

    /**
     * Get data from EHealth API (Contract Requests) resolving the class via app().
     */
    protected function sendRequest(string $token): PromiseInterface|EHealthResponse
    {
        return app(ApiContractRequest::class)
            ->withToken($token)
            ->getMany($this->contractType, [
                'contractor_legal_entity_id' => $this->legalEntity->uuid,
                'page' => $this->page,
            ]);
    }

    /**
     * Inside ContractRequestSync.php -> processResponse()
     */
    protected function processResponse(?EHealthResponse $response): void
    {
        $validatedData = $response->validate();

        if (!empty($validatedData)) {
            foreach ($validatedData as $item) {
                // Add PARTIAL status to the item before saving
                $item['sync_status'] = JobStatus::PARTIAL->value;
                Repository::contractRequest()->saveFromEHealth($item, $this->contractType, $this->legalEntity);
            }
        }
    }

    protected function nextPageJob(): static
    {
        return new static($this->legalEntity, $this->nextEntity, $this->isFirstLogin, $this->user, $this->contractType, $this->page + 1, $this->standalone);
    }

    protected function getAdditionalMiddleware(): array
    {
        return [
            new RateLimited('ehealth-contract-request-get')
        ];
    }

    /**
     * Chain logic:
     * 1. If current is CAPITATION -> Next is REIMBURSEMENT.
     * 2. If current is REIMBURSEMENT -> Finish (or go to nextEntity).
     */
    /**
     * Inside ContractRequestSync.php -> getNextEntityJob()
     */
    protected function getNextEntityJob(): ?EHealthJob
    {
        // 1. If we still have types to process (e.g., CAPITATION -> REIMBURSEMENT)
        if ($this->contractType === 'CAPITATION') {
            return new self(
                $this->legalEntity,
                $this->nextEntity,
                $this->isFirstLogin,
                $this->user,
                'REIMBURSEMENT',
                standalone: $this->standalone
            );
        }

        $nextJob = $this->standalone
            ? new CompleteSyncJob($this->legalEntity, isFirstLogin: $this->isFirstLogin)
            : $this->nextEntity;

        return $this->getContractRequestDetailsStartJob($this->legalEntity, $nextJob);
    }
}
