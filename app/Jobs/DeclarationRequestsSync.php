<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Core\EHealthJob;
use App\Enums\User\Role;
use App\Models\LegalEntity;
use App\Models\DeclarationRequest;
use App\Models\Employee\Employee;
use App\Classes\eHealth\EHealth;
use App\Repositories\Repository;
use App\Enums\Declaration\RequestStatus;
use App\Traits\BatchLegalEntityQueries;
use GuzzleHttp\Promise\PromiseInterface;
use App\Classes\eHealth\EHealthResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ItemNotFoundException;
use Illuminate\Queue\Middleware\RateLimited;

class DeclarationRequestsSync extends EHealthJob
{
    use BatchLegalEntityQueries;

    public const string BATCH_NAME = 'DeclarationRequestsSync';

    public const string SCOPE_REQUIRED = 'declaration_request:read';

    public const string ENTITY = LegalEntity::ENTITY_DECLARATION;

    /**
     * Get the approved declaration requests, which wait for the doctor signature.
     * OWNER, REORGANIZATION_OWNER and ADMIN sync the requests of the whole legal entity.
     * Otherwise, doctors sync only the requests addressed to them.
     *
     * @param  string  $token
     * @return PromiseInterface|EHealthResponse
     */
    protected function sendRequest(string $token): PromiseInterface|EHealthResponse
    {
        $query = [
            'page' => $this->page,
            'legal_entity_id' => $this->legalEntity->uuid,
            'status' => RequestStatus::APPROVED->value
        ];

        if ($this->user->hasAllowedRole(Role::DOCTOR) && !$this->user->hasAllowedRole([Role::OWNER, Role::REORGANIZATION_OWNER, Role::ADMIN])) {
            $query['employee_id'] = Employee::forParty($this->user->partyId)
                ->filterByLegalEntityId($this->legalEntity->id)
                ->doctor()
                ->active()
                ->firstOrFail()->uuid;
        }

        return EHealth::declarationRequest()->withToken($token)->getMany(query: $query);
    }

    /**
     * Store the requests with PARTIAL sync status, so their details are synced next.
     *
     * The list has only the patient ID, so a patient who created the request in their own cabinet
     * and is not in the MIS yet is created by DeclarationRequestDetailsSync from the request details.
     *
     * @param  EHealthResponse|null  $response
     * @return void
     */
    protected function processResponse(?EHealthResponse $response): void
    {
        $requests = [];

        foreach ($response->validate() as $request) {
            // A request created through the previous API version has neither the patient nor the data to sign
            if (empty($request['person_id'])) {
                Log::warning('Declaration request ' . $request['id'] . ' is skipped: it has no patient');

                continue;
            }

            try {
                $requests[] = Repository::declarationRequest()->prepareFromList($request);
            } catch (ItemNotFoundException) {
                // The request of an employee who is not synced yet is stored by the next sync
                Log::warning('Declaration request ' . $request['id'] . ' is skipped: its employee is not synced yet');
            }
        }

        // Upsert needs the same columns in every row, while an optional field may be missing from some requests
        $columns = array_fill_keys(array_keys(array_merge(...$requests)), null);

        DeclarationRequest::upsert(
            array_map(static fn (array $request): array => [...$columns, ...$request], $requests),
            uniqueBy: ['uuid']
        );
    }

    /**
     * Get additional middleware configurations for the job.
     *
     * @return array Returns an array of middleware configurations to be applied to the job
     */
    protected function getAdditionalMiddleware(): array
    {
        return [
            new RateLimited('ehealth-declaration-request-get')
        ];
    }

    /**
     * Get the next entity job to be scheduled after the whole list is stored.
     *
     * If the job is standalone, returns a CompleteSync job for the current legal entity.
     * Otherwise, returns a chain of DeclarationRequestDetailsSync jobs, which covers the requests stored by this job.
     *
     * @return EHealthJob|null
     */
    protected function getNextEntityJob(): ?EHealthJob
    {
        if ($this->standalone) {
            return new CompleteSync($this->legalEntity, isFirstLogin: $this->isFirstLogin);
        }

        return $this->getDeclarationRequestsStartJob($this->legalEntity, $this->nextEntity, $this->user)
            ?? new CompleteSync($this->legalEntity, isFirstLogin: $this->isFirstLogin);
    }
}
