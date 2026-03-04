<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Classes\eHealth\EHealthResponse;
use App\Core\EHealthJob;
use App\Classes\eHealth\EHealth;
use App\Traits\BatchLegalEntityQueries;
use App\Traits\ProcessesPartyVerificationResponses;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Support\Facades\Log;
use Throwable;

class PartyVerificationSync extends EHealthJob
{
    use BatchLegalEntityQueries;
    use ProcessesPartyVerificationResponses;

    public const string BATCH_NAME = 'PartyVerificationFullSync';
    public const string SCOPE_REQUIRED = 'party_verification:read';

    /**
     * @throws ConnectionException
     */
    protected function sendRequest(string $token): PromiseInterface|EHealthResponse
    {
        return EHealth::party()->withToken($token)->getMany(page: $this->page);
    }

    protected function processResponse(?EHealthResponse $response): void
    {
        $this->processPartyVerificationResponse($response, $this->legalEntity);
    }

    protected function getAdditionalMiddleware(): array
    {
        return [new RateLimited('ehealth-party-verification-get')];
    }

    /**
     * Handle a job failure.
     */
    public function failed(Throwable|null $exception): void
    {
        Log::error('Job [PartyVerificationSync] failed.', [
            'legal_entity_id' => $this->legalEntity->id ?? 'unknown',
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
        ]);
    }

    /**
     * Get next entity job if needed.
     *
     * @return EHealthJob|null
     */
    protected function getNextEntityJob(): ?EHealthJob
    {
        return $this->standalone || !$this->nextEntity
            ? new CompleteSync($this->legalEntity, isFirstLogin: $this->isFirstLogin)
            : $this->nextEntity;
    }
}
