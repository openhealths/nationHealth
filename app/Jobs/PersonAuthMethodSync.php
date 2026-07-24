<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Core\EHealthJob;
use App\Models\LegalEntity;
use App\Models\Person\Person;
use App\Classes\eHealth\EHealth;
use App\Repositories\Repository;
use GuzzleHttp\Promise\PromiseInterface;
use App\Classes\eHealth\EHealthResponse;
use Illuminate\Queue\Middleware\RateLimited;

class PersonAuthMethodSync extends EHealthJob
{
    public const string BATCH_NAME = 'PersonAuthMethodSync';

    public const string SCOPE_REQUIRED = 'person:read';

    public const string ENTITY = LegalEntity::ENTITY_DECLARATION;

    public function __construct(
        public Person $person,
        public ?LegalEntity $legalEntity,
        protected ?EHealthJob $nextEntity = null,
        public bool $standalone = false,
    ) {
        parent::__construct(legalEntity: $legalEntity, nextEntity: $nextEntity, standalone: $standalone);
    }

    /**
     * Get declarations data from EHealth API
     *
     * @param  string  $token
     * @return PromiseInterface|EHealthResponse
     */
    protected function sendRequest(string $token): PromiseInterface|EHealthResponse|null
    {
        try {
            $response = EHealth::person()->withToken($token)->getAuthMethods($this->person->uuid);
        } catch (\Throwable $e) {
            \Log::warning("PersonAuthMethodSync: Failed to get authentication methods for person " . $this->person->uuid . ". Error: " . $e->getMessage() . " Code: " . $e->getCode());

            // In some cases person is present in our database but not in EHealth, so we need to handle 404 error gracefully
            if ($e->getCode() === 404) {
                return null;
            }

            throw $e;
        }

        return $response;
    }

    /**
     * Store or update all the declarations data in the database
     *
     * @param  EHealthResponse|null  $response
     * @throws \Throwable
     */
    protected function processResponse(?EHealthResponse $response): void
    {
        if (\is_null($response)) {
            return;
        }

        $authMethods = $response->validate();

        Repository::authenticationMethod()->sync($this->person, $authMethods);
    }

    /**
     * Get additional middleware configurations for the job.
     *
     * @return array Returns an array of middleware configurations to be applied to the job
     */
    protected function getAdditionalMiddleware(): array
    {
        return [
            new RateLimited('person-authentication-method-get')
        ];
    }

    /**
     * Get the next entity job to be scheduled after PersonAuthMethodSync completes.
     *
     * If the job is standalone, returns a CompleteSync job for the current legal entity.
     * Otherwise, returns the next entity job in the chain.
     *
     * @return EHealthJob|null
     */
    protected function getNextEntityJob(): ?EHealthJob
    {
        $nextEntity = $this->getConfidantPersonStartJob($this->legalEntity, $this->nextEntity) ?? $this->nextEntity;

        return $this->standalone || !$nextEntity
            ? new CompleteSync($this->legalEntity, isFirstLogin: $this->isFirstLogin)
            : $nextEntity;
    }
}
