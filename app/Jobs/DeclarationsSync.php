<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Core\EHealthJob;
use App\Enums\User\Role;
use App\Models\LegalEntity;
use App\Models\Employee\Employee;
use App\Classes\eHealth\EHealth;
use App\Repositories\Repository;
use App\Traits\BatchLegalEntityQueries;
use GuzzleHttp\Promise\PromiseInterface;
use App\Classes\eHealth\EHealthResponse;
use Illuminate\Queue\Middleware\RateLimited;

class DeclarationsSync extends EHealthJob
{
    use BatchLegalEntityQueries;

    public const string BATCH_NAME = 'DeclarationsSync';

    public const string SCOPE_REQUIRED = 'declaration:read';

    public const string ENTITY = LegalEntity::ENTITY_DECLARATION;

    /**
     * Get declarations data from EHealth API
     * OWNER, REORGANIZATION_OWNER and ADMIN sync all declarations of the legal entity.
     * Otherwise, doctors sync only their own declarations.
     *
     * @param  string  $token
     * @return PromiseInterface|EHealthResponse
     */
    protected function sendRequest(string $token): PromiseInterface|EHealthResponse
    {
        $query = ['page' => $this->page, 'legal_entity_id' => $this->legalEntity->uuid];

        if ($this->user->hasAllowedRole(Role::DOCTOR) && !$this->user->hasAllowedRole([Role::OWNER, Role::REORGANIZATION_OWNER, Role::ADMIN])) {
            $query['employee_id'] = Employee::forParty($this->user->partyId)
                ->filterByLegalEntityId($this->legalEntity->id)
                ->doctor()
                ->firstOrFail()->uuid;
        }

        return EHealth::declaration()->withToken($token)->getMany(query: $query, groupByEntities: true);
    }

    /**
     * Store or update all the declarations data in the database
     *
     * @param  EHealthResponse|null  $response
     * @throws \Throwable
     */
    protected function processResponse(?EHealthResponse $response): void
    {
        $declarations = $response->validate();

        Repository::declaration()->storeMany($declarations, $this->legalEntity);
    }

    /**
     * Get additional middleware configurations for the job.
     *
     * @return array Returns an array of middleware configurations to be applied to the job
     */
    protected function getAdditionalMiddleware(): array
    {
        return [
            new RateLimited('ehealth-declaration-get')
        ];
    }

    /**
     * Get the next entity job to be scheduled after DeclarationSync completes.
     *
     * If the job is standalone, returns a CompleteSync job for the current legal entity.
     * Otherwise, returns a chain of DeclarationRequestDetailsSync jobs.
     *
     * @return EHealthJob|null
     */
    protected function getNextEntityJob(): ?EHealthJob
    {
        return $this->standalone
            ? new CompleteSync($this->legalEntity, isFirstLogin: $this->isFirstLogin)
            : $this->getDeclarationRequestsStartJob($this->legalEntity, $this->nextEntity, $this->user);
    }
}
