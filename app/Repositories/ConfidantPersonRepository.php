<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Arr;
use App\Enums\JobStatus;
use App\Enums\Person\AuthenticationMethod;
use App\Models\Person\Person;
use App\Models\Relations\Phone;
use App\Models\Relations\Document;
use App\Models\Relations\ConfidantPerson;
use Exception;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class ConfidantPersonRepository
{
    /**
     * Link the confidant persons listed in the declaration to the subject person.
     * The declaration data has no identifier of a confidant, so only a person already recorded here is linked;
     * one that is not is left to the confidant person relationships sync. A person is matched by name and birth
     * date together with the tax ID, or, when there is none, the UNZR or an identity document number.
     *
     * @param  array  $data
     * @return void
     */
    public function addConfidantPerson(array $data): void
    {
        $personId = Arr::pull($data, 'person_id');

        foreach ($data as $personData) {
            $documentsRelationship = Arr::pull($personData, 'documents_relationship');
            $names = Arr::pull($personData, 'names', []);

            if (empty($names)) {
                $names = [[
                    'language' => 'uk',
                    'first_name' => $personData['first_name'] ?? null,
                    'last_name' => $personData['last_name'] ?? null,
                    'second_name' => $personData['second_name'] ?? null
                ]];
            }

            $primaryName = collect($names)->firstWhere('language', 'uk') ?? ($names[0] ?? []);

            $query = Person::whereHas('names', static function (Builder $query) use ($primaryName): void {
                $query->where('language', $primaryName['language'] ?? null)
                    ->where('first_name', $primaryName['first_name'] ?? null)
                    ->where('last_name', $primaryName['last_name'] ?? null);
            })->where('birth_date', $personData['birth_date']);

            $documentNumbers = array_filter(array_column($personData['documents_person'] ?? [], 'number'));

            if (!empty($personData['tax_id'])) {
                $query->where('tax_id', $personData['tax_id']);
            } elseif (!empty($personData['unzr']) || $documentNumbers !== []) {
                // Without a tax ID a namesake born on the same day is told apart by the UNZR or an identity document
                $query->where(static function (Builder $query) use ($personData, $documentNumbers): void {
                    if (!empty($personData['unzr'])) {
                        $query->where('unzr', $personData['unzr']);
                    }

                    if ($documentNumbers !== []) {
                        $query->orWhereHas(
                            'documents',
                            static fn (Builder $query): Builder => $query->whereIn('number', $documentNumbers)
                        );
                    }
                });
            }

            $person = $query->first();

            if ($person === null) {
                continue;
            }

            $confidantPerson = ConfidantPerson::updateOrCreate(
                ['person_id' => $person->id, 'subject_person_id' => $personId],
                ['sync_status' => JobStatus::PARTIAL->value]
            );

            if (!empty($documentsRelationship)) {
                Repository::declarationRequest()->syncRelatedData(
                    $confidantPerson,
                    'documentsRelationship',
                    $documentsRelationship,
                    Document::class
                );
            }
        }
    }

    /**
     * Create confidant person relationship from signed eHealth API response.
     *
     * @param  array  $responseData  The signed response data from eHealth API
     * @param  string  $subjectPersonUuid  The UUID of the person who needs a confidant
     * @param  array  $personData  Data for creating person if it's not exist in our DB
     * @return ConfidantPerson
     * @throws Exception
     */
    public function createFromSignedResponse(array $responseData, string $subjectPersonUuid, array $personData): ConfidantPerson
    {
        // Find the confidant person by UUID from the API response
        $confidantPersonUuid = $responseData['confidant_person_id'];
        $confidantPerson = Person::whereUuid($confidantPersonUuid)->first();

        if (!$confidantPerson) {
            // Create new person if it doesn't exist in our DB
            $personDataArray = Arr::toSnakeCase($personData);
            $phones = Arr::pull($personDataArray, 'phones', []);
            $documents = Arr::pull($personDataArray, 'documents', []);
            $names = Arr::pull($personDataArray, 'names', []);

            // Set the UUID from the API response to ensure consistency
            $personDataArray['uuid'] = $confidantPersonUuid;
            unset($personDataArray['id']);

            $confidantPerson = Person::create($personDataArray);

            // The name lives in its own table, so it never reaches the person through the fillable attributes
            if (!empty($names)) {
                $confidantPerson->names()->createMany($names);
            }

            if (!empty($documents)) {
                Repository::declarationRequest()->syncRelatedData(
                    $confidantPerson,
                    'documents',
                    $documents,
                    Document::class
                );
            }

            // Add phones if provided
            if (!empty($phones)) {
                Repository::declarationRequest()->syncRelatedData(
                    $confidantPerson,
                    'phones',
                    $phones,
                    Phone::class
                );
            }
        }

        // Find the subject person (the person who needs a confidant)
        $subjectPerson = Person::whereUuid($subjectPersonUuid)->firstOrFail();

        // A signed request always starts a new relationship, so an earlier one with the same confidant stays as it was
        $confidantPersonRelation = ConfidantPerson::create([
            'person_id' => $confidantPerson->id,
            'subject_person_id' => $subjectPerson->id,
            'sync_status' => JobStatus::COMPLETED
        ]);

        // Save documents relationship
        $confidantPersonRelation->documentsRelationship()->createMany(
            collect($responseData['documents_relationship'] ?? [])
                ->map(static fn (array $document): array => [
                    'type' => $document['type'],
                    'number' => $document['number'],
                    'issued_by' => $document['issued_by'],
                    'issued_at' => $document['issued_at'],
                    'active_to' => $document['active_to'] ?? null
                ])
                ->all()
        );

        return $confidantPersonRelation;
    }

    /**
     * Resolve the confidant behind a relationship, recording one the registry knows but we do not.
     *
     * The relationship carries the personal data masked, the surname and the initials arriving as a single
     * value, so it only fills a name that is missing altogether — a record already holding a real name, of a
     * person registered here in full, keeps it.
     *
     * @param  string  $confidantPersonUuid
     * @param  array  $confidantPersonData
     * @return Person
     */
    private function resolveConfidantPerson(string $confidantPersonUuid, array $confidantPersonData): Person
    {
        $person = Person::whereUuid($confidantPersonUuid)->first() ?? Person::create([
            'uuid' => $confidantPersonUuid,
            'gender' => $confidantPersonData['gender'],
            'tax_id' => $confidantPersonData['tax_id'] ?? null,
            'no_tax_id' => $confidantPersonData['no_tax_id'] ?? false,
            'unzr' => $confidantPersonData['unzr'] ?? null
        ]);

        if (!empty($confidantPersonData['name']) && $person->names()->doesntExist()) {
            $person->names()->create([
                'language' => 'uk',
                'first_name' => $confidantPersonData['name']
            ]);
        }

        return $person;
    }

    /**
     * Sync confidant person relationships from API response.
     *
     * @param  array  $responseData  The validated relationships, whose own identifier arrives as uuid
     * @param  string  $subjectPersonUuid  The UUID of the person who needs confidants
     * @return Collection
     */
    public function sync(array $responseData, string $subjectPersonUuid): Collection
    {
        // Find the subject person (the person who needs confidants)
        $subjectPerson = Person::whereUuid($subjectPersonUuid)->firstOrFail();

        // The response holds every relationship of the subject, so the ones it lacks no longer exist, and the ones
        // recorded before they got an identifier are superseded by the synced ones
        ConfidantPerson::whereSubjectPersonId($subjectPerson->id)
            ->where(static fn (Builder $query): Builder => $query->whereNull('uuid')
                ->orWhereNotIn('uuid', array_column($responseData, 'uuid')))
            ->get()
            ->each(static function (ConfidantPerson $relationship): void {
                $relationship->documentsRelationship()->delete();
                $relationship->delete();
            });

        $syncedConfidantPersons = collect();

        // The same confidant can hold several relationships with the subject, and their personal data comes
        // repeated in each of them, so the person is read and refreshed once per confidant rather than once
        // per relationship. Every relationship still becomes a record of its own.
        foreach (collect($responseData)->groupBy('confidant_person.person_id') as $confidantPersonUuid => $relationships) {
            $confidantPersonData = $relationships->first()['confidant_person'];

            $confidantPerson = $this->resolveConfidantPerson((string) $confidantPersonUuid, $confidantPersonData);

            if (!empty($confidantPersonData['phones'])) {
                Repository::phone()->syncPhones($confidantPerson, $confidantPersonData['phones']);
            }

            if (!empty($confidantPersonData['documents_person'])) {
                Repository::document()->sync($confidantPerson, $confidantPersonData['documents_person']);
            }

            foreach ($relationships as $relationshipData) {
                $confidantPersonRelation = ConfidantPerson::updateOrCreate(
                    ['uuid' => $relationshipData['uuid']],
                    [
                        'person_id' => $confidantPerson->id,
                        'subject_person_id' => $subjectPerson->id,
                        'active_to' => $relationshipData['active_to'] ?? null,
                        'sync_status' => JobStatus::COMPLETED
                    ]
                );

                // The documents belong to the relationship, so they are replaced along with it
                $confidantPersonRelation->documentsRelationship()->delete();
                $confidantPersonRelation->documentsRelationship()->createMany(
                    collect($relationshipData['documents_relationship'] ?? [])
                        ->map(static fn (array $document): array => [
                            'type' => $document['type'],
                            'number' => $document['number'],
                            'issued_by' => $document['issued_by'] ?? null,
                            'issued_at' => $document['issued_at'] ?? null,
                            'active_to' => $document['active_to'] ?? null
                        ])
                        ->all()
                );

                $syncedConfidantPersons->push($confidantPersonRelation);
            }
        }

        return $syncedConfidantPersons;
    }

    /**
     * Find suitable authentication method for deactivating a confidant person relationship.
     *
     * @param  Person  $mainPerson  The person who has confidant relationships
     * @param  string  $confidantPersonRelationUuid  UUID of the relationship being deactivated
     * @return array{auth_method_uuid: string|null, error: string|null}
     */
    public function findAuthMethodForDeactivation(Person $mainPerson, string $confidantPersonRelationUuid): array
    {
        // Get the confidant person being deactivated
        $confidantBeingDeactivated = ConfidantPerson::whereUuid($confidantPersonRelationUuid)->first();

        if (!$confidantBeingDeactivated) {
            return ['auth_method_uuid' => null, 'error' => 'Confidant person relationship not found'];
        }

        // Find a suitable authentication method from the main person
        // Must be THIRD_PERSON type, value != confidant person being deactivated, and not expired
        $authMethod = $mainPerson->authenticationMethods()
            ->whereType(AuthenticationMethod::THIRD_PERSON)
            ->where('value', '!=', $confidantBeingDeactivated->person->uuid)
            ->where(function ($query) {
                $query->whereNull('ehealth_ended_at')
                    ->orWhere('ehealth_ended_at', '>', now());
            })
            ->first();

        // If no valid auth method found, check if we can proceed without authorization
        if (!$authMethod) {
            // The authorization is needed only while another confidant still stands for the person
            $hasAnotherActiveConfidant = $mainPerson->confidantPersons()
                ->where('person_id', '!=', $confidantBeingDeactivated->person_id)
                ->where(static fn (Builder $query): Builder => $query->whereNull('active_to')
                    ->orWhere('active_to', '>', now()))
                ->exists();

            if (!$hasAnotherActiveConfidant) {
                return ['auth_method_uuid' => null, 'error' => null];
            }

            // Multiple confidants but no valid auth method - this shouldn't happen
            return [
                'auth_method_uuid' => null,
                'error' => 'Не знайдено дійсного методу автентифікації для деактивації. Спочатку синхронізуйте методи автентифікації.'
            ];
        }

        return ['auth_method_uuid' => $authMethod->uuid, 'error' => null];
    }
}
