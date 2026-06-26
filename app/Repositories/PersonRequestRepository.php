<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Arr;
use App\Models\Person\Person;
use App\Models\Person\PersonRequest;
use Illuminate\Support\Facades\DB;
use Throwable;

class PersonRequestRepository
{
    /**
     * Create person request.
     *
     * @param  array  $validatedData
     * @param  array|null  $confidantPersonData
     * @return void
     * @throws Throwable
     */
    public function create(array $validatedData, ?array $confidantPersonData = null): void
    {
        $this->hydrateConfidantPersonId($validatedData, $confidantPersonData);

        $personData = $validatedData['person'];
        $personFields = Arr::except(
            $personData,
            ['documents', 'phones', 'authentication_methods', 'addresses', 'confidant_person']
        );

        DB::transaction(static function () use ($personFields, $personData) {
            $personRequest = PersonRequest::create($personFields);

            $personRequest->documents()->createMany($personData['documents']);
            $personRequest->addresses()->createMany($personData['addresses']);
            $personRequest->authenticationMethods()->createMany($personData['authentication_methods']);

            if (!empty($personData['phones'])) {
                $personRequest->phones()->createMany($personData['phones']);
            }

            if (!empty($personData['confidant_person'])) {
                $confidant = $personRequest->confidantPersons()->create([
                    'uuid' => $personData['uuid'],
                    'person_id' => $personData['confidant_person']['person_id'], // Who is a confidant person
                    'subject_person_id' => $personRequest->personId // Who needs a confidant person
                ]);

                if (!empty($personData['confidant_person']['documents_relationship'])) {
                    $confidant->documentsRelationship()->createMany(
                        $personData['confidant_person']['documents_relationship']
                    );
                }
            }
        });
    }

    /**
     * Update existing person request.
     *
     * @param  int  $id
     * @param  array  $validatedData
     * @param  array|null  $confidantPersonData
     * @return void
     * @throws Throwable
     */
    public function updateDraft(int $id, array $validatedData, ?array $confidantPersonData = null): void
    {
        $this->hydrateConfidantPersonId($validatedData, $confidantPersonData);

        $personData = $validatedData['person'];
        $personFields = Arr::except(
            $personData,
            ['documents', 'phones', 'authentication_methods', 'addresses', 'confidant_person']
        );

        DB::transaction(static function () use ($id, $personFields, $personData) {
            $personRequest = PersonRequest::findOrFail($id);
            $personRequest->update($personFields);

            $personRequest->documents()->delete();
            $personRequest->documents()->createMany($personData['documents']);

            $personRequest->addresses()->delete();
            $personRequest->addresses()->createMany($personData['addresses']);

            $personRequest->authenticationMethods()->delete();
            $personRequest->authenticationMethods()->createMany($personData['authentication_methods']);

            $personRequest->phones()->delete();
            if (!empty($personData['phones'])) {
                $personRequest->phones()->createMany($personData['phones']);
            }

            // Confidant persons - now using HasMany relationship
            $existingConfidants = $personRequest->confidantPersons;

            if (!empty($personData['confidant_person'])) {
                // Delete existing confidant persons
                foreach ($existingConfidants as $existingConfidant) {
                    $existingConfidant->documentsRelationship()->delete();
                    $existingConfidant->delete();
                }

                $confidant = $personRequest->confidantPersons()->create([
                    'uuid' => $personData['uuid'],
                    'person_id' => $personData['confidant_person']['person_id'],
                    'subject_person_id' => $personRequest->personId
                ]);

                if (!empty($personData['confidant_person']['documents_relationship'])) {
                    $confidant->documentsRelationship()->createMany(
                        $personData['confidant_person']['documents_relationship']
                    );
                }
            } elseif ($existingConfidants->isNotEmpty()) {
                // Delete all existing confidant persons
                foreach ($existingConfidants as $existingConfidant) {
                    $existingConfidant->documentsRelationship()->delete();
                    $existingConfidant->delete();
                }
            }
        });
    }

    /**
     * Create person request for update.
     *
     * @param  array  $validatedData
     * @return void
     * @throws Throwable
     */
    public function update(array $validatedData): void
    {
        $personData = $validatedData['person'];
        $personFields = Arr::except(
            $personData,
            ['documents', 'phones', 'addresses', 'confidant_person']
        );

        DB::transaction(static function () use ($personFields, $personData) {
            $personRequest = PersonRequest::create($personFields);

            $personRequest->documents()->createMany($personData['documents']);
            $personRequest->addresses()->createMany($personData['addresses']);

            if (!empty($personData['phones'])) {
                $personRequest->phones()->createMany($personData['phones']);
            }
        });
    }

    /**
     * Update person request status by provided UUID.
     *
     * @param  array  $response
     * @return void
     */
    public function updateStatusByUuid(array $response): void
    {
        PersonRequest::whereUuid($response['id'])->update(['status' => $response['status']]);
    }

    /**
     * Set confidant person ID from provided UUID.
     *
     * @param  array  $validatedData
     * @param  array|null  $confidantPersonData
     * @return void
     */
    private function hydrateConfidantPersonId(array &$validatedData, ?array $confidantPersonData): void
    {
        if (empty($confidantPersonData)) {
            return;
        }

        $confidantPerson = Person::firstOrCreate(
            ['uuid' => $confidantPersonData['uuid']],
            Arr::toSnakeCase($confidantPersonData)
        );

        $validatedData['person']['confidant_person']['person_id'] = $confidantPerson->id;
    }
}
