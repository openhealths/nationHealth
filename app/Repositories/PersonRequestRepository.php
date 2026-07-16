<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Arr;
use App\Models\Person\Person;
use App\Models\Person\PersonRequest;
use App\Models\Relations\ConfidantPerson;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOneOrMany;
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
            ['names', 'documents', 'phones', 'authentication_methods', 'addresses', 'confidant_person']
        );

        DB::transaction(static function () use ($personFields, $personData) {
            $personRequest = PersonRequest::create($personFields);

            $personRequest->names()->createMany($personData['names']);
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
            ['names', 'documents', 'phones', 'authentication_methods', 'addresses', 'confidant_person']
        );

        DB::transaction(function () use ($id, $personFields, $personData): void {
            $personRequest = PersonRequest::findOrFail($id);
            $personRequest->update($personFields);

            $this->syncChildren($personRequest->names(), $personData['names']);
            $this->syncChildren($personRequest->documents(), $personData['documents']);
            $this->syncChildren($personRequest->addresses(), $personData['addresses']);
            $this->syncChildren(
                $personRequest->authenticationMethods(),
                $personData['authentication_methods']
            );
            $this->syncChildren($personRequest->phones(), $personData['phones'] ?? []);

            $this->syncConfidantPerson($personRequest, $personData);
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
            ['names', 'documents', 'phones', 'addresses', 'confidant_person']
        );

        DB::transaction(static function () use ($personFields, $personData) {
            $personRequest = PersonRequest::create($personFields);

            $personRequest->names()->createMany($personData['names']);
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
     * Sync child rows positionally: update the row already sitting at each position, create the missing ones and drop the surplus tail.
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
     * Sync the confidant person: keep the existing row when it still points to the same person,
     * drop the ones that no longer belong to the request.
     *
     * @param  PersonRequest  $personRequest
     * @param  array  $personData
     * @return void
     */
    private function syncConfidantPerson(PersonRequest $personRequest, array $personData): void
    {
        $confidantPersonData = $personData['confidant_person'] ?? [];
        $existingConfidants = $personRequest->confidantPersons()->get();

        if (empty($confidantPersonData)) {
            $this->deleteConfidantPersons($existingConfidants);

            return;
        }

        $confidant = $existingConfidants->firstWhere('person_id', $confidantPersonData['person_id']);

        $this->deleteConfidantPersons(
            $existingConfidants->reject(static fn (ConfidantPerson $existing): bool => $existing->is($confidant))
        );

        $attributes = [
            'uuid' => $personData['uuid'],
            'person_id' => $confidantPersonData['person_id'], // Who is a confidant person
            'subject_person_id' => $personRequest->personId // Who needs a confidant person
        ];

        if ($confidant) {
            $confidant->update($attributes);
        } else {
            $confidant = $personRequest->confidantPersons()->create($attributes);
        }

        $this->syncChildren(
            $confidant->documentsRelationship(),
            $confidantPersonData['documents_relationship'] ?? []
        );
    }

    /**
     * Delete confidant persons along with the documents proving the relationship.
     *
     * @param  Collection  $confidantPersons
     * @return void
     */
    private function deleteConfidantPersons(Collection $confidantPersons): void
    {
        foreach ($confidantPersons as $confidantPerson) {
            $confidantPerson->documentsRelationship()->delete();
            $confidantPerson->delete();
        }
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
            Arr::toSnakeCase(Arr::except($confidantPersonData, ['names']))
        );

        if ($confidantPerson->wasRecentlyCreated && !empty($confidantPersonData['names'])) {
            $confidantPerson->names()->createMany(Arr::toSnakeCase($confidantPersonData['names']));
        }

        $validatedData['person']['confidant_person']['person_id'] = $confidantPerson->id;
    }
}
