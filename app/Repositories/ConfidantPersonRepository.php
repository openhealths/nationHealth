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
use Illuminate\Support\Collection;

class ConfidantPersonRepository
{
    public function addConfidantPerson(array $data): void
    {
        $personId = Arr::pull($data, 'person_id');

        $personsData = [];

        foreach ($data as $key => $value) {
            $personsData[] = ['person' => $value];
        }

        foreach ($personsData as $data) {
            $personData = $data['person'];

            // $preferredWayCommunication = Arr::pull($personData, 'preferred_way_communication', null);
            $documentsPerson = Arr::pull($personData, 'documents_person', null);
            $documentsRelationship = Arr::pull($personData, 'documents_relationship', null);
            $phones = Arr::pull($personData, 'phones', []);
            // $relationType = Arr::pull($personData, 'relation_type', null);

            unset($personData['relation_type'], $personData['preferred_way_communication']);

            $query = Person::where('first_name', $personData['first_name'])
                ->where('last_name', $personData['last_name'])
                ->where('birth_date', $personData['birth_date']);

            if (!empty($personData['tax_id'])) {
                $query->where('tax_id', $personData['tax_id']);
            }

            $person = $query->first();

            if (empty($person)) {
                $person = Person::forceCreate($personData);

                Repository::declarationRequest()->syncRelatedData(
                    $person,
                    'documents',
                    $documentsPerson,
                    Document::class
                );

                if (!empty($phones)) {
                    Repository::declarationRequest()->syncRelatedData($person, 'phones', $phones, Phone::class);
                }
            }

            $confidantPerson = ConfidantPerson::updateOrCreate(
                ['person_id' => $person->id],
                [
                    'subject_person_id' => $personId,
                    'sync_status' => JobStatus::PARTIAL->value
                ]
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
            $personDataArray = $personData;
            $phones = Arr::pull($personDataArray, 'phones', []);

            // Set the UUID from the API response to ensure consistency
            $personDataArray['uuid'] = $confidantPersonUuid;
            unset($personDataArray['id']);

            $confidantPerson = Person::create(Arr::toSnakeCase($personDataArray));

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

        // Create or update the confidant person relationship
        $confidantPersonRelation = ConfidantPerson::updateOrCreate(
            [
                'person_id' => $confidantPerson->id,
                'subject_person_id' => $subjectPerson->id,
            ],
            [
                'sync_status' => JobStatus::COMPLETED,
                'uuid' => $responseData['id']
            ]
        );

        // Save documents relationship
        if (!empty($responseData['documents_relationship'])) {
            // Create new documents
            foreach ($responseData['documents_relationship'] as $document) {
                $confidantPersonRelation->documentsRelationship()->create([
                    'type' => $document['type'],
                    'number' => $document['number'],
                    'issued_by' => $document['issued_by'],
                    'issued_at' => $document['issued_at'],
                    'active_to' => $document['active_to'] ?? null
                ]);
            }
        }

        return $confidantPersonRelation;
    }

    /**
     * Sync confidant person relationships from API response.
     *
     * @param  array  $responseData  The API response data containing confidant persons
     * @param  string  $subjectPersonUuid  The UUID of the person who needs confidants
     * @return Collection
     */
    public function sync(array $responseData, string $subjectPersonUuid): Collection
    {
        // Find the subject person (the person who needs confidants)
        $subjectPerson = Person::whereUuid($subjectPersonUuid)->firstOrFail();

        // First, completely remove all existing confidant person relationships for this subject
        // to ensure a clean sync without duplicates
        ConfidantPerson::where('subject_person_id', $subjectPerson->id)->delete();

        $syncedConfidantPersons = collect();

        // Process each relationship individually (don't group by person_id)
        // Each relationship should be a separate ConfidantPerson record
        foreach ($responseData as $relationshipData) {
            $confidantPersonData = $relationshipData['confidant_person'];
            $confidantPersonUuid = $confidantPersonData['person_id'];

            // Find the confidant person
            $confidantPerson = Person::whereUuid($confidantPersonUuid)->first();

            if (!$confidantPerson) {
                // If person doesn't exist, skip this relationship
                continue;
            }

            // Sync phones if provided (update person data each time)
            if (!empty($confidantPersonData['phones'])) {
                Repository::phone()->syncPhones($confidantPerson, $confidantPersonData['phones']);
            }

            // Sync documents if provided (update person data each time)
            if (!empty($confidantPersonData['documents_person'])) {
                Repository::document()->sync($confidantPerson, $confidantPersonData['documents_person']);
            }

            // Create a separate confidant person relationship for each relationship
            $confidantPersonRelation = ConfidantPerson::create([
                'uuid' => $relationshipData['id'],
                'person_id' => $confidantPerson->id,
                'subject_person_id' => $subjectPerson->id,
                'active_to' => $relationshipData['active_to'] ?? null,
                'sync_status' => JobStatus::COMPLETED
            ]);

            // Add relationship documents for this specific relationship
            if (!empty($relationshipData['documents_relationship'])) {
                foreach ($relationshipData['documents_relationship'] as $document) {
                    $confidantPersonRelation->documentsRelationship()->create([
                        'type' => $document['type'],
                        'number' => $document['number'],
                        'issued_by' => $document['issued_by'] ?? null,
                        'issued_at' => $document['issued_at'] ?? null,
                        'active_to' => $document['active_to'] ?? null
                    ]);
                }
            }

            $syncedConfidantPersons->push($confidantPersonRelation);
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
            // Check if the person has only this one confidant relationship
            $totalConfidantPersons = $mainPerson->confidantPersons()->count();

            if ($totalConfidantPersons === 1) {
                // Only one confidant person, proceed without authorization
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
