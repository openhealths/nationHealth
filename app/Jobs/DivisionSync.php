<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Core\EHealthJob;
use App\Models\LegalEntity;
use App\Dto\Division\DivisionGetMany as DivisionListData;
use App\Classes\eHealth\EHealth;
use App\Repositories\Repository;
use GuzzleHttp\Promise\PromiseInterface;
use App\Classes\eHealth\EHealthResponse;
use Illuminate\Support\Collection;
use Illuminate\Queue\Middleware\RateLimited;

class DivisionSync extends EHealthJob
{
    public const string BATCH_NAME = 'DivisionSync';

    public const string SCOPE_REQUIRED = 'division:read';

    public const string ENTITY = LegalEntity::ENTITY_DIVISION;

    // Get data from EHealth API
    protected function sendRequest(string $token): PromiseInterface|EHealthResponse
    {
        return EHealth::division()
            ->withToken($token)
            ->getMany(query: [
                'page' => $this->page,
                'page_size' => config('ehealth.api.page_size_max'),
            ]);
    }

    // Store or update data in the database
    protected function processResponse(?EHealthResponse $response): void
    {
        $validated = $response?->validate();

        if ($validated === null) {
            return;
        }

        $divisions = propertyObjectMapper()->map(new Collection(['divisions' => $validated]), DivisionListData::class);

        if ($divisions->isEmpty()) {
            return;
        }

        Repository::division()->saveDivisionsList($divisions, $this->legalEntity);
    }

    /**
     * Get additional middleware configurations for the job.
     *
     * @return array Returns an array of middleware configurations to be applied to the job
     */
    protected function getAdditionalMiddleware(): array
    {
        return [
            new RateLimited('ehealth-division-get')
        ];
    }

    /**
     * Get the next entity job to be scheduled after DivisionSync completes.
     *
     * If the job is standalone, returns a CompleteSync job for the current legal entity.
     * Otherwise, returns a HealthcareServiceSync job, passing the current legal entity and nextEntity.
     * If HealthcareServiceSync has started, the nextEntity as next incoming job will be started after it's chain.
     *
     * @return EHealthJob|null
     */
    protected function getNextEntityJob(): ?EHealthJob
    {
        return $this->standalone
            ? new CompleteSync($this->legalEntity, isFirstLogin: $this->isFirstLogin)
            : new HealthcareServiceSync(
                $this->legalEntity,
                $this->nextEntity,
                $this->isFirstLogin
            );
    }
}
