<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Core\EHealthJob;
use App\Enums\User\Role;
use App\Enums\Declaration\Status;
use App\Models\LegalEntity;
use App\Models\Employee\Employee;
use App\Classes\eHealth\EHealth;
use App\Repositories\Repository;
use GuzzleHttp\Promise\PromiseInterface;
use App\Classes\eHealth\EHealthResponse;
use Illuminate\Queue\Middleware\RateLimited;

class DeclarationsSync extends EHealthJob
{
    public const string BATCH_NAME = 'DeclarationsSync';

    public const array FULL_SYNC_BATCHES = [
        self::BATCH_NAME, DeclarationDetailsSync::BATCH_NAME, DeclarationRequestsSync::BATCH_NAME,
        DeclarationRequestDetailsSync::BATCH_NAME, ConfidantPersonSync::BATCH_NAME, PersonAuthMethodSync::BATCH_NAME,
    ];

    public const string SCOPE_REQUIRED = 'declaration:read';

    public const string ENTITY = LegalEntity::ENTITY_DECLARATION;

    // Without a status filter the eHealth list defaults to active declarations.
    protected Status $status = Status::ACTIVE;

    public function __construct(
        ?LegalEntity $legalEntity = null,
        ?EHealthJob $nextEntity = null,
        bool $isFirstLogin = false,
        int $page = 1,
        bool $standalone = false,
        Status $status = Status::ACTIVE,
    ) {
        parent::__construct($legalEntity, $nextEntity, $isFirstLogin, $page, $standalone);
        $this->status = $status;
    }

    protected function nextPageJob(): static
    {
        return new static($this->legalEntity, $this->nextEntity, $this->isFirstLogin, $this->page + 1, $this->standalone, $this->status);
    }

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
        $query = ['page' => $this->page, 'legal_entity_id' => $this->legalEntity->uuid, 'status' => $this->status->value];

        if ($this->user->hasAllowedRole(Role::DOCTOR) && !$this->user->hasAllowedRole([Role::OWNER, Role::REORGANIZATION_OWNER, Role::ADMIN])) {
            $query['employee_id'] = Employee::forParty($this->user->partyId)
                ->filterByLegalEntityId($this->legalEntity->id)
                ->doctor()
                ->active()
                ->firstOrFail()->uuid;
        }

        $api = EHealth::declaration()->withToken($token);

        // Once a declaration ends the doctor may no longer receive its patient's data.
        if ($this->status !== Status::ACTIVE) {
            return $api->getRegisterPage($this->legalEntity->uuid, $this->page, $this->status, $query['employee_id'] ?? null);
        }

        return $api->getMany(query: $query, groupByEntities: true);
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

        if ($this->status !== Status::ACTIVE) {
            Repository::declaration()->storeRegisterPage($declarations, $this->legalEntity);

            return;
        }

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
     * Otherwise, returns a DeclarationRequestsSync job, so that the requests created in the patient cabinet
     * are stored before the chain of DeclarationRequestDetailsSync jobs is built.
     *
     * @return EHealthJob|null
     */
    protected function getNextEntityJob(): ?EHealthJob
    {
        $nextStatus = $this->status->nextForSync();
        if ($nextStatus !== null) {
            return new static($this->legalEntity, $this->nextEntity, $this->isFirstLogin, 1, $this->standalone, $nextStatus);
        }

        return $this->afterAllStatuses();
    }

    protected function afterAllStatuses(): EHealthJob
    {
        return $this->standalone
            ? new CompleteSync($this->legalEntity, isFirstLogin: $this->isFirstLogin)
            : new DeclarationRequestsSync(
                legalEntity: $this->legalEntity,
                nextEntity: $this->nextEntity,
                isFirstLogin: $this->isFirstLogin
            );
    }
}
