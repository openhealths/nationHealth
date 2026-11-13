<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Classes\eHealth\Api\Responses\Collections\HealthcareServiceDetails;
use App\Classes\eHealth\EHealth;
use App\Classes\eHealth\EHealthResponse;
use App\Core\EHealthJob;
use App\Dto\HealthcareService\Model as HealthcareServiceData;
use App\Exceptions\EHealth\EHealthConnectionException;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Exceptions\EHealth\EHealthValidationException;
use App\Models\LegalEntity;
use App\Repositories\Repository;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Queue\Middleware\RateLimited;
use Throwable;

class HealthcareServiceSync extends EHealthJob
{
    public const string BATCH_NAME = 'HealthcareServiceSync';

    public const string SCOPE_REQUIRED = 'healthcare_service:read';

    public const string ENTITY = LegalEntity::ENTITY_HEALTHCARE_SERVICE;

    /**
     * Get data from EHealth API.
     *
     * @param  string  $token
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthResponseException|EHealthValidationException
     */
    protected function sendRequest(string $token): PromiseInterface|EHealthResponse
    {
        return EHealth::healthcareService()
            ->withToken($token)
            ->getMany(['page' => $this->page]);
    }

    /**
     * Store or update data in the database.
     *
     * @param  EHealthResponse|null  $response
     * @return void
     * @throws Throwable
     */
    protected function processResponse(?EHealthResponse $response): void
    {
        $responses = $response?->validate();

        if (empty($responses)) {
            return;
        }

        $healthcareServices = array_map(
            static fn (HealthcareServiceDetails $item): HealthcareServiceData => objectMapper()->map($item, HealthcareServiceData::class),
            $responses
        );

        Repository::healthcareService()->sync($this->legalEntity, ...$healthcareServices);
    }

    /**
     * Get additional middleware configurations for the job.
     *
     * @return array Returns an array of middleware configurations to be applied to the job
     */
    protected function getAdditionalMiddleware(): array
    {
        return [
            new RateLimited('ehealth-healthcare-service-get')
        ];
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
