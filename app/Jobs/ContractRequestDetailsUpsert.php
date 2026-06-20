<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Classes\eHealth\Api\ContractRequest as ApiContractRequest;
use App\Classes\eHealth\EHealthResponse;
use App\Core\EHealthJob;
use App\Enums\JobStatus;
use App\Models\Contracts\ContractRequest;
use App\Models\LegalEntity;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Queue\Middleware\RateLimited;

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
        $ehealthData = $response?->validate();

        if (!empty($ehealthData)) {
            $this->contractRequestModel->update([
                'contractor_base' => $ehealthData['contractor_base'] ?? $this->contractRequestModel->contractor_base,
                'contractor_payment_details' => $ehealthData['contractor_payment_details'] ?? null,
                'contractor_divisions' => $ehealthData['contractor_divisions'] ?? null,
                'external_contractors' => $ehealthData['external_contractors'] ?? null,
                'nhs_signer_id' => $ehealthData['nhs_signer']['id'] ?? null,
                'nhs_signer_base' => $ehealthData['nhs_signer_base'] ?? null,
                'nhs_contract_price' => $ehealthData['nhs_contract_price'] ?? null,
                'nhs_payment_method' => $ehealthData['nhs_payment_method'] ?? null,
                'status' => $ehealthData['status'] ?? $this->contractRequestModel->status,
                'status_reason' => $ehealthData['status_reason'] ?? null,
                'data' => $ehealthData,
                'sync_status' => JobStatus::COMPLETED->value,
            ]);
        }
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
            ? new CompleteSync($this->legalEntity, isFirstLogin: $this->isFirstLogin)
            : $this->nextEntity;
    }
}
