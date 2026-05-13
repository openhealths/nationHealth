<?php

declare(strict_types=1);

namespace App\Jobs;

use Throwable;
use App\Core\Arr;
use Carbon\Carbon;
use App\Enums\Status;
use App\Core\EHealthJob;
use App\Enums\JobStatus;
use App\Models\LegalEntity;
use App\Models\Declaration;
use App\Models\Person\Person;
use App\Repositories\Repository;
use App\Classes\eHealth\EHealth;
use Spatie\Permission\Models\Role;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Queue\SerializesModels;
use App\Classes\eHealth\EHealthResponse;
use App\Traits\BatchLegalEntityQueries;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Http\Client\ConnectionException;

class DeclarationDetailsSync extends EHealthJob
{
    use BatchLegalEntityQueries;
    use Dispatchable;
    use SerializesModels;

    protected const int RATE_LIMIT_DELAY = 3;

    public const string BATCH_NAME = 'DeclarationDetailsSync';

    public const string SCOPE_REQUIRED = 'declaration:read';

    public const string ENTITY = LegalEntity::ENTITY_DECLARATION;

    public function __construct(
        public Declaration $declaration,
        public ?LegalEntity $legalEntity,
        protected ?EHealthJob $nextEntity = null,
        public bool $standalone = false,
    ) {
        parent::__construct(legalEntity: $legalEntity, nextEntity: $nextEntity, standalone: $standalone);
    }

    // Get data from EHealth API

    /**
     * @throws ConnectionException
     */
    protected function sendRequest(string $token): PromiseInterface|EHealthResponse|null
    {
        return EHealth::declaration()->withToken($token)->getDeclarationById(uuid: $this->declaration->uuid);
    }

    /**
     * Store or update declaration data in the database
     *
     * @param  EHealthResponse|null  $response
     * @throws Throwable
     */
    protected function processResponse(?EHealthResponse $response): void
    {
        $validatedData = $response->validate();

        $validatedData['signed_at'] = Carbon::parse($validatedData['signed_at'])->format('Y-m-d H:i:s');
        $validatedData['inserted_at'] = Carbon::parse($validatedData['inserted_at'])->format('Y-m-d H:i:s');
        $validatedData['updated_at'] = Carbon::parse($validatedData['updated_at'])->format('Y-m-d H:i:s');

        $urgentData = $response->getUrgent();

        $person = $validatedData['person']; // Extract person data for separate processing
        $confidantPerson = $person['confidant_person'] ?? [];

        Arr::forget($validatedData, [
            'scope',
            'person',
            'division',
            'employee',
            'created_at',
            'updated_at',
            'legal_entity',
            'declaration_request_uuid',
        ]);

        $orig = Arr::toSnakeCase($this->declaration->toArray()); // Original data from DB for comparison

        unset($orig['person']);

        Log::info('Processing DeclarationDetailsSync for declaration:' . $this->declaration->id . ', LE:' . ($this->legalEntity->id ?? 'N/A'));

        $person = array_intersect_key($person, array_flip(new Person()->getFillable()));

        $person['id'] = $person['uuid'];
        $person['addresses'] ??= [];
        $person['documents'] ??= [];
        $person['phones'] ??= [];
        $person['authentication_methods'][] = $urgentData['authentication_method_current'] ?? [];

        unset($person['uuid']);

        Repository::declarationRequest()->syncPersonData($person);

        echo "Person synced: " . $person['id'] . PHP_EOL;

        if (!empty($confidantPerson)) {
            $confidantPerson['person_id'] = $this->declaration->person->id;

            Repository::confidantPerson()->addConfidantPerson($confidantPerson);

            echo "Confidant Person for this person has been synced: " . PHP_EOL;
        }

        foreach ($validatedData as $key => $value) {
            if (isset($orig[$key]) && $orig[$key] === $value) {
                unset($validatedData[$key]);
            }
        }

        $validatedData['sync_status'] = JobStatus::COMPLETED->value;

        if (!empty($validatedData)) {
            $this->declaration->update($validatedData);
        }

        if ($this->legalEntity->status === Status::REORGANIZED->value) {
            Repository::declaration()->storeDataForReorganizedLegalEntity($response->validate(), $this->legalEntity);
        }
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
            : $this->getConfidantPersonStartJob($this->legalEntity, $this->nextEntity);
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
