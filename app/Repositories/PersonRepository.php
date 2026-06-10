<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Arr;
use App\Models\Person\Person;
use App\Models\Person\PersonRequest;
use Throwable;

class PersonRepository
{
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
        $personFields = Arr::except(
            $personData,
            ['documents', 'phones', 'authentication_methods', 'addresses', 'confidant_person']
        );
        $personRequestUuid = $personFields['uuid'];
        // set created person_id as uuid
        Arr::set($personFields, 'uuid', $uuid);

        $person = Person::create($personFields);

        // associate person request with person
        $personRequest = PersonRequest::whereUuid($personRequestUuid)->firstOrFail();
        $personRequest->person()->associate($person);

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
        $personFields = Arr::except($personData, ['documents', 'phones', 'addresses']);
        $personRequestUuid = $personFields['uuid'];
        // set created person_id as uuid
        Arr::set($personFields, 'uuid', $uuid);

        $person = Person::whereUuid($uuid)->firstOrFail();
        $person->update($personFields);

        // associate person request
        $personRequest = PersonRequest::whereUuid($personRequestUuid)->firstOrFail();
        $personRequest->person()->associate($person);

        // update documents
        $person->documents()->delete();
        $person->documents()->createMany($personData['documents']);

        // update addresses
        $person->addresses()->delete();
        $person->addresses()->createMany($personData['addresses']);

        // update phones
        if (!empty($personData['phones'])) {
            $person->phones()->delete();
            $person->phones()->createMany($personData['phones']);
        }
    }

    /**
     * Update verification status by provided ID or UUID.
     *
     * @param  int|string  $personId
     * @param  string  $verificationStatus
     * @return void
     */
    public function updateVerificationStatusById(int|string $personId, string $verificationStatus): void
    {
        $query = Person::query();

        if (is_numeric($personId)) {
            $query->where('id', $personId);
        } else {
            $query->where('uuid', $personId);
        }

        $query->update(['verification_status' => $verificationStatus]);
    }

    /**
     * Update synchronization person data status by provided ID or UUID.
     *
     * @param  int|string  $personId
     * @param  bool  $synchronizationStatus
     *
     * @return void
     */
    public function updateSynchronizationStatusById(int|string $personId, bool $synchronizationStatus): void
    {
        $query = Person::query();

        if (is_numeric($personId)) {
            $query->where('id', $personId);
        } else {
            $query->where('uuid', $personId);
        }

        $query->update(['is_syncing' => $synchronizationStatus]);
    }
}
