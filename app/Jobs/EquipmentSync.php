<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Core\EHealthJob;
use App\Exceptions\EHealth\EHealthConnectionException;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Exceptions\EHealth\EHealthValidationException;
use App\Repositories\Repository;
use App\Classes\eHealth\EHealth;
use GuzzleHttp\Promise\PromiseInterface;
use App\Classes\eHealth\EHealthResponse;
use App\Models\LegalEntity;
use Illuminate\Queue\Middleware\RateLimited;
use Throwable;

class EquipmentSync extends EHealthJob
{
    public const string BATCH_NAME = 'EquipmentSync';

    public const string SCOPE_REQUIRED = 'equipment:read';

    public const string ENTITY = LegalEntity::ENTITY_EQUIPMENT;

    /**
     * Get data from EHealth API.
     *
     * @param  string  $token
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthResponseException|EHealthValidationException
     */
    protected function sendRequest(string $token): PromiseInterface|EHealthResponse
    {
        return EHealth::equipment()->withToken($token)->getMany(['page' => $this->page]);
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
        $healthcareServicesData = $this->normalizeDate($response?->validate());

        if (empty($healthcareServicesData)) {
            return;
        }

        Repository::equipment()->sync($response?->map($healthcareServicesData));
    }

    /**
     * Get additional middleware configurations for the job.
     *
     * @return array Returns an array of middleware configurations to be applied to the job
     */
    protected function getAdditionalMiddleware(): array
    {
        return [
            new RateLimited('ehealth-equipment-get')
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
