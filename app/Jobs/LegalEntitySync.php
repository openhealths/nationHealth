<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Core\EHealthJob;
use App\Repositories\Repository;
use App\Classes\eHealth\EHealth;
use GuzzleHttp\Promise\PromiseInterface;
use App\Classes\eHealth\EHealthResponse;
use Illuminate\Queue\Middleware\RateLimited;

/**
 * This job is responsible for finalizing a full synchronization operation between different data sources
 *
 * @package App\Jobs
 */
class LegalEntitySync extends EHealthJob
{
    public const string BATCH_NAME = 'LegalEntitySync';

    // Get data from EHealth API (here it mostly dummy method)
    protected function sendRequest(string $token): PromiseInterface|EHealthResponse|null
    {
        return EHealth::legalEntity()
            ->withToken($token)
            ->getLegators($this->legalEntity->uuid, $this->page);
    }

    // Store or update data in the database (here it mostly dummy method)
    protected function processResponse(?EHealthResponse $response): void
    {
        $validated = $response->validate();

        Repository::legalEntity()->saveLegators($this->legalEntity, $validated);

        $this->sendEntityNotification('legal_entity', 'completed');
    }

    /**
     * Get additional middleware configurations for the job.
     *
     * @return array Returns an array of middleware configurations to be applied to the job
     */
    protected function getAdditionalMiddleware(): array
    {
        return [
            new RateLimited('legal-entity-legators-get')
        ];
    }

    // Get next entity job if needed
    protected function getNextEntityJob(): ?EHealthJob
    {
        return $this->standalone || !$this->nextEntity
            ? new CompleteSync($this->legalEntity, isFirstLogin: $this->isFirstLogin)
            : $this->nextEntity;
    }
}
