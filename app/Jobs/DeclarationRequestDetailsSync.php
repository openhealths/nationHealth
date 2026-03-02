<?php

declare(strict_types=1);

namespace App\Jobs;

use Throwable;
use App\Core\Arr;
use App\Core\EHealthJob;
use App\Enums\JobStatus;
use App\Models\LegalEntity;
use App\Classes\eHealth\EHealth;
use Spatie\Permission\Models\Role;
use Illuminate\Support\Collection;
use App\Models\DeclarationRequest;
use Illuminate\Support\Facades\Log;
use Illuminate\Queue\SerializesModels;
use App\Traits\BatchLegalEntityQueries;
use GuzzleHttp\Promise\PromiseInterface;
use App\Classes\eHealth\EHealthResponse;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Http\Client\ConnectionException;

class DeclarationRequestDetailsSync extends EHealthJob
{
    use Dispatchable;
    use SerializesModels;
    use BatchLegalEntityQueries;

    protected const int RATE_LIMIT_DELAY = 4;

    public const string BATCH_NAME = 'DeclarationRequestDetailsSync';

    public const string SCOPE_REQUIRED = 'declaration_request:read';

    public const string ENTITY = LegalEntity::ENTITY_DECLARATION;

    public function __construct(
        public DeclarationRequest $declarationRequest,
        public ?LegalEntity $legalEntity,
        protected ?EHealthJob $nextEntity = null,
        public bool $standalone = false,
    ) {
        parent::__construct(legalEntity: $legalEntity, nextEntity: $nextEntity, standalone: $standalone);
    }

    /**
     * Get declaration request data from EHealth API
     *
     * @param  string  $token
     * @return PromiseInterface|EHealthResponse|null
     * @throws ConnectionException
     */
    protected function sendRequest(string $token): PromiseInterface|EHealthResponse|null
    {
        return EHealth::declarationRequest()->withToken($token)->get(uuid: $this->declarationRequest->uuid);
    }

    /**
     * Store or update all the declaration request data in the database
     *
     * @param  EHealthResponse|null  $response
     * @throws Throwable
     */
    protected function processResponse(?EHealthResponse $response): void
    {
        $validatedData = $response->validate();

        Log::info('Processing DeclarationRequestDetailsUpsert for declaration request:' . $this->declarationRequest->id . ', LE:' . ($this->legalEntity->id ?? 'N/A'));

        // This parameters should be stored by previous are DeclarationsSync jobs
        Arr::forget($validatedData, [
            'division_uuid',
            'employee_uuid',
            'legal_entity_uuid',
            'person_uuid',
            'declaration_uuid'
        ]);

        $validatedData['sync_status'] = JobStatus::COMPLETED->value;

        $this->declarationRequest->update($validatedData);
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

    // Get next entity job if needed
    protected function getNextEntityJob(): ?EHealthJob
    {
        return $this->standalone || !$this->nextEntity
            ? new CompleteSync($this->legalEntity, isFirstLogin: $this->isFirstLogin)
            : $this->nextEntity;
    }

    /**
     * Determine which authentication guards define the given role.
     * Checks only the 'web' and 'ehealth' guards.
     * Queries Spatie\Permission\Models\Role by name and guard_name.
     * Returns an empty collection if the role is not defined for any of the checked guards.
     *
     * @param  string  $role  The role name to check across guards.
     * @return Collection<int, string> Collection of guard names that have this role defined.
     */
    protected function getGuardsForRole(string $role): Collection
    {
        $guards = collect(['web', 'ehealth']);

        return $guards->filter(
            fn ($guard) =>
                Role::where('name', $role)
                    ->where('guard_name', $guard)
                    ->exists()
        );
    }
}
