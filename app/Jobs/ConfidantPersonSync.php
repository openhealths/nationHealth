<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Exceptions\EHealth\EHealthConnectionException;
use Throwable;
use App\Core\EHealthJob;
use App\Enums\JobStatus;
use App\Models\LegalEntity;
use App\Models\Person\Person;
use App\Classes\eHealth\EHealth;
use Spatie\Permission\Models\Role;
use Illuminate\Support\Collection;
use Illuminate\Queue\SerializesModels;
use App\Classes\eHealth\EHealthResponse;
use App\Models\Relations\ConfidantPerson;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Support\Facades\Log;

class ConfidantPersonSync extends EHealthJob
{
    use Dispatchable;
    use SerializesModels;

    protected const int RATE_LIMIT_DELAY = 3;

    public const string BATCH_NAME = 'ConfidantPersonSync';

    public const string SCOPE_REQUIRED = 'person:read';

    public const string ENTITY = LegalEntity::ENTITY_DECLARATION;

    protected ?Person $person;

    public function __construct(
        public ConfidantPerson $confidantPerson,
        public ?LegalEntity $legalEntity,
        protected ?EHealthJob $nextEntity = null,
        public bool $standalone = false,
    ) {
        parent::__construct(legalEntity: $legalEntity, nextEntity: $nextEntity, standalone: $standalone);

        $this->person = $this->confidantPerson->person;
    }

    // Get data from EHealth API

    /**
     * @throws EHealthConnectionException
     */
    protected function sendRequest(string $token): PromiseInterface|EHealthResponse|null
    {
        return EHealth::person()->withToken($token)->searchForPersonByParams($this->getPersonSearchData());
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

        foreach ($validatedData as $data) {
            if ($this->checkPersonData($data)) {
                $personData = $data;

                break;
            }
        }

        if (empty($personData)) {
            Log::info("Confidant Person sync failed: person for subject_person " . $this->confidantPerson->subjectPerson->uuid . "not found in EHealth response");

            echo "Confidant Person sync failed: person for subject_person " . $this->confidantPerson->subjectPerson->uuid . "not found in EHealth response" . PHP_EOL;

            return;
        }

        // Check if this UUID already exists on another person
        $existingPerson = Person::whereUuid($personData['id'])->where('id', '!=', $this->person->id)->first();

        if ($existingPerson) {
            Log::warning("UUID {$personData['id']} already exists on person $existingPerson->id, skipping UUID update for person {$this->person->id}");
            echo "Warning: UUID {$personData['id']} already exists, skipping update" . PHP_EOL;
        } else {
            // Only update UUID if it doesn't exist on another person
            $this->person->update(['uuid' => $personData['id']]);
        }

        $this->confidantPerson->setSyncStatus(JobStatus::COMPLETED);

        echo "Confidant Person with uuid=" . $personData['id'] . " has been synced for Person" . $this->confidantPerson->subjectPerson->id . PHP_EOL;
    }

    /**
     * Retrieves the person search data for synchronization with the Confidant system.
     *
     * This method prepares and returns an array containing the necessary data
     * for searching and identifying a person in the external Confidant system.
     *
     * @return array The formatted person search data array
     */
    protected function getPersonSearchData(): array
    {
        return [
            'first_name' => $this->person->first_name,
            'last_name' => $this->person->last_name,
            'birth_date' => convertToYmd($this->person->birth_date),
            'tax_id' => $this->person->tax_id ?? null,
            'phone_number' => $this->person->phones->first()?->number ?? null,
        ];
    }

    /**
     * Validates the person data array to ensure it the exatly the Person that seacrhed for.
     *
     * This method checks whether the provided data array contains valid person information
     * required for synchronization with the Confidant system.
     *
     * @param  array  $data  The person data array to validate
     * @return bool Returns true if the data is valid, false otherwise
     */
    protected function checkPersonData(array $data): bool
    {
        return $data['first_name'] === $this->person->first_name
            && $data['last_name'] === $this->person->last_name
            && $data['birth_date'] === convertToYmd($this->person->birth_date)
            && ($this->person->tax_id === null || $data['tax_id'] === $this->person->tax_id);
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
