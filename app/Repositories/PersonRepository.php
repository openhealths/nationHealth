<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Arr;
use App\Models\Person\Person;
use App\Models\Person\PersonRequest;
use App\Models\Relations\PersonVerificationDetail;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOneOrMany;
use Throwable;

class PersonRepository
{
    /**
     * Response keys that are stored through relations, so they must never reach the person attributes.
     */
    private const array RELATION_FIELDS = [
        'names',
        'documents',
        'phones',
        'authentication_methods',
        'addresses',
        'confidant_person'
    ];

    /**
     * Create person.
     *
     * @param  array  $validatedData
     * @param  string  $uuid
     * @return void
     * @throws Throwable
     */
    public function create(array $validatedData, string $uuid): void
    {
        $personData = $validatedData['person'];
        $personFields = Arr::except($personData, self::RELATION_FIELDS);
        $personRequestUuid = $personFields['uuid'];
        // set created person_id as uuid
        Arr::set($personFields, 'uuid', $uuid);

        $person = Person::create($personFields);

        // associate person request with person
        $personRequest = PersonRequest::whereUuid($personRequestUuid)->firstOrFail();
        $personRequest->person()->associate($person);

        $person->names()->createMany($personData['names']);
        $person->documents()->createMany($personData['documents']);
        $person->addresses()->createMany($personData['addresses']);
        $person->authenticationMethods()->createMany($personData['authentication_methods']);

        // Save related data
        if (!empty($personData['phones'])) {
            $person->phones()->createMany($personData['phones']);
        }

        if (!empty($personData['confidant_person'])) {
            $confidant = $person->confidantPersons()->create([
                'uuid' => $personData['uuid'],
                'person_id' => $personData['confidant_person']['person_id'],
                'subject_person_id' => $person->id
            ]);

            if (!empty($personData['confidant_person']['documents_relationship'])) {
                $confidant->documentsRelationship()->createMany(
                    $personData['confidant_person']['documents_relationship']
                );
            }
        }
    }

    /**
     * Update person with related relationships.
     *
     * @param  array  $validatedData
     * @param  string  $uuid
     * @return void
     * @throws Throwable
     */
    public function update(array $validatedData, string $uuid): void
    {
        $personData = $validatedData['person'];
        $personFields = Arr::except($personData, self::RELATION_FIELDS);
        $personRequestUuid = $personFields['uuid'];
        // set created person_id as uuid
        Arr::set($personFields, 'uuid', $uuid);

        $person = Person::whereUuid($uuid)->firstOrFail();
        $person->update($personFields);

        // associate person request
        $personRequest = PersonRequest::whereUuid($personRequestUuid)->firstOrFail();
        $personRequest->person()->associate($person);

        $this->syncRelations($person, $personData);
    }

    /**
     * Sync person with related relationships.
     *
     * @param  array  $personData
     * @param  string  $uuid
     * @return void
     * @throws Throwable
     */
    public function sync(array $personData, string $uuid): void
    {
        $personFields = Arr::except($personData, self::RELATION_FIELDS);

        // set created person_id as uuid
        Arr::set($personFields, 'uuid', $uuid);

        $person = Person::whereUuid($uuid)->firstOrFail();
        $person->update($personFields);

        $this->syncRelations($person, $personData);
    }

    /**
     * Sync the person relations that both update and sync rebuild from the eHealth response.
     *
     * @param  Person  $person
     * @param  array  $personData
     * @return void
     */
    private function syncRelations(Person $person, array $personData): void
    {
        $this->syncChildren($person->names(), $personData['names']);
        $this->syncChildren($person->documents(), $personData['documents']);
        $this->syncChildren($person->addresses(), $personData['addresses']);

        if (!empty($personData['phones'])) {
            $this->syncChildren($person->phones(), $personData['phones']);
        }
    }

    /**
     * Sync child rows positionally: update the row already sitting at each position, create the missing ones
     * and drop the surplus tail.
     *
     * @param  HasOneOrMany  $relation
     * @param  array  $rows
     * @return void
     */
    private function syncChildren(HasOneOrMany $relation, array $rows): void
    {
        $existingRows = $relation->get();
        $rows = array_values($rows);

        foreach ($rows as $index => $row) {
            $existingRow = $existingRows[$index] ?? null;

            if ($existingRow) {
                $existingRow->update($row);
            } else {
                $relation->create($row);
            }
        }

        $existingRows->slice(count($rows))->each(static fn (Model $extra): ?bool => $extra->delete());
    }

    /**
     * Update verification status.
     *
     * @param  int  $personId
     * @param  string  $verificationStatus
     * @return void
     */
    public function updateVerificationStatusById(int $personId, string $verificationStatus): void
    {
        Person::whereId($personId)->update(['verification_status' => $verificationStatus]);
    }

    /**
     * Store the person verification result of each registry.
     *
     * @param  int  $personId
     * @param  array  $details
     * @return void
     */
    public function syncVerificationDetails(int $personId, array $details): void
    {
        $columns = array_fill_keys(new PersonVerificationDetail()->getFillable(), null);

        $rows = Arr::map($details, static fn (array $detail, string $source): array => [
            ...$columns,
            ...$detail,
            'person_id' => $personId,
            'source' => $source
        ]);

        PersonVerificationDetail::upsert(array_values($rows), ['person_id', 'source']);
    }

    /**
     * Update synchronization person data status.
     *
     * @param  int  $personId
     * @param  bool  $synchronizationStatus
     * @return void
     */
    public function updateSynchronizationStatusById(int $personId, bool $synchronizationStatus): void
    {
        Person::whereId($personId)->update(['is_syncing' => $synchronizationStatus]);
    }
}
