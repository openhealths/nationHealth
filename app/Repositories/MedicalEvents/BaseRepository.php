<?php

declare(strict_types=1);

namespace App\Repositories\MedicalEvents;

use App\Models\MedicalEvents\Sql\Identifier;
use Illuminate\Database\Eloquent\Model;

abstract class BaseRepository
{
    // Add logical operators after implementing MongoDB, like Model|MongoModel
    // For now, it's only SQL models for the IDE to prompt
    public function __construct(protected Model $model)
    {
    }

    /**
     * Sync coding relationship using updateOrCreate (structure: {code, system}).
     *
     * @param  mixed  $existing
     * @param  array|null  $fromApi
     * @param  string  $relationshipName
     * @return Model|null
     */
    protected function syncCoding(?Model $existing, ?array $fromApi, string $relationshipName): ?Model
    {
        // Check if API data is valid
        if (empty($fromApi)) {
            return $existing?->{$relationshipName};
        }

        if ($existing && $existing->{$relationshipName}) {
            // Update existing coding
            $coding = $existing->{$relationshipName};
            $coding->update([
                'code' => $fromApi['code'],
                'system' => $fromApi['system']
            ]);

            return $coding;
        }

        // Create new coding only if we have data
        return Repository::coding()->store($fromApi);
    }

    /**
     * Sync codeable concept relationship using updateOrCreate (structure: {text, coding: [{code, system}]}).
     *
     * @param  mixed  $existing
     * @param  array|null  $newData
     * @param  string  $relationshipName
     * @return Model|null
     */
    protected function syncCodeableConcept(?Model $existing, ?array $newData, string $relationshipName): ?Model
    {
        if (empty($newData)) {
            return $existing?->{$relationshipName};
        }

        if ($existing && $existing->{$relationshipName}) {
            $codeableConcept = $existing->{$relationshipName};
            $this->updateCodeableConcept($codeableConcept, $newData);

            return $codeableConcept;
        }

        return Repository::codeableConcept()->store($newData);
    }

    /**
     * Update an existing identifier with new data.
     *
     * @param  Identifier  $identifier
     * @param  array  $newData
     * @return void
     */
    protected function updateIdentifier(Identifier $identifier, array $newData): void
    {
        $identifier->update([
            'value' => $newData['identifier']['value'],
            'display_value' => $newData['identifier']['display_value'] ?? null
        ]);

        $typeData = $newData['identifier']['type'] ?? null;
        if ($typeData) {
            if ($identifier->type->isNotEmpty()) {
                // Update existing codeableConcept
                $codeableConcept = $identifier->type->first();
                $codeableConcept->update(['text' => $typeData['text'] ?? null]);

                if ($codeableConcept->coding->isNotEmpty()) {
                    $codeableConcept->coding->first()->update([
                        'code' => $typeData['coding'][0]['code'],
                        'system' => $typeData['coding'][0]['system']
                    ]);
                } else {
                    // Create missing coding
                    $codeableConcept->coding()->create([
                        'code' => $typeData['coding'][0]['code'],
                        'system' => $typeData['coding'][0]['system']
                    ]);
                }
            } else {
                // Create missing codeableConcept with coding
                Repository::codeableConcept()->attach($identifier, $newData);
            }
        }
    }

    /**
     * Sync identifier relationship using updateOrCreate (structure: {identifier: {value, type: [codeableConcept]}}).
     *
     * @param  mixed  $existing
     * @param  array|null  $newData
     * @param  string  $relationshipName
     * @return Model|null
     */
    protected function syncIdentifier(?Model $existing, ?array $newData, string $relationshipName): ?Model
    {
        if (empty($newData)) {
            return $existing?->{$relationshipName};
        }

        if ($existing && $existing->{$relationshipName}) {
            $identifier = $existing->{$relationshipName};
            $this->updateIdentifier($identifier, $newData);

            return $identifier;
        }

        $identifier = Repository::identifier()->store(
            $newData['identifier']['value'],
            $newData['identifier']['display_value'] ?? null
        );
        Repository::codeableConcept()->attach($identifier, $newData);

        return $identifier;
    }

    /**
     * Sync multiple identifiers for a BelongsToMany relationship.
     *
     * @param  Model|null  $existing
     * @param  array|null  $items
     * @param  string  $relationshipName
     * @return array
     */
    protected function syncIdentifiers(?Model $existing, ?array $items, string $relationshipName): array
    {
        if (empty($items)) {
            return [];
        }

        $identifierIds = [];

        if ($existing) {
            $existingIdentifiers = $existing->{$relationshipName};

            foreach ($items as $index => $item) {
                $existingIdentifier = $existingIdentifiers[$index] ?? null;

                if ($existingIdentifier) {
                    $this->updateIdentifier($existingIdentifier, $item);
                    $identifierIds[] = $existingIdentifier->id;
                } else {
                    $identifier = Repository::identifier()->store(
                        $item['identifier']['value'],
                        $item['identifier']['display_value'] ?? null
                    );
                    if (isset($item['identifier']['type'])) {
                        Repository::codeableConcept()->attach($identifier, $item);
                    }
                    $identifierIds[] = $identifier->id;
                }
            }
        } else {
            foreach ($items as $item) {
                $identifier = Repository::identifier()->store(
                    $item['identifier']['value'],
                    $item['identifier']['display_value'] ?? null
                );
                if (isset($item['identifier']['type'])) {
                    Repository::codeableConcept()->attach($identifier, $item);
                }
                $identifierIds[] = $identifier->id;
            }
        }

        return $identifierIds;
    }

    /**
     * Update an existing codeable concept with new data.
     */
    protected function updateCodeableConcept(Model $codeableConcept, array $newData): void
    {
        $codeableConcept->update(['text' => $newData['text'] ?? null]);

        if ($codeableConcept->coding->isNotEmpty()) {
            $codeableConcept->coding->first()->update([
                'code' => $newData['coding'][0]['code'],
                'system' => $newData['coding'][0]['system']
            ]);
        }
    }

    /**
     * Sync multiple codeable concepts for a BelongsToMany relationship.
     *
     * @param  Model|null  $existing
     * @param  array|null  $items
     * @param  string  $relationshipName
     * @return array
     */
    protected function syncCodeableConcepts(?Model $existing, ?array $items, string $relationshipName): array
    {
        if (empty($items)) {
            return [];
        }

        $ids = [];

        if ($existing) {
            $existingCollection = $existing->{$relationshipName};

            foreach ($items as $index => $item) {
                $existingConcept = $existingCollection[$index] ?? null;

                if ($existingConcept) {
                    $this->updateCodeableConcept($existingConcept, $item);
                    $ids[] = $existingConcept->id;
                } else {
                    $concept = Repository::codeableConcept()->store($item);
                    $ids[] = $concept->id;
                }
            }
        } else {
            foreach ($items as $item) {
                $concept = Repository::codeableConcept()->store($item);
                $ids[] = $concept->id;
            }
        }

        return $ids;
    }
}
