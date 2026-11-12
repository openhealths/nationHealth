<?php

declare(strict_types=1);

namespace App\Repositories\MedicalEvents;

use App\Enums\JobStatus;
use App\Exceptions\EHealth\EHealthValidationException;
use App\Exceptions\MedicalEvents\ClinicalStorageException;
use App\Models\MedicalEvents\Sql\CodeableConcept;
use App\Models\MedicalEvents\Sql\Identifier;
use App\Models\Person\Person;
use App\Models\Preperson;
use App\Repositories\MedicalEvents\Repository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

abstract class BaseRepository
{
    /** Check before submission so a missing migration cannot leave a remote document without a local record. */
    protected function assertStorageSchema(array $requirements, string $messageKey, string $exceptionClass = ClinicalStorageException::class): void
    {
        $missing = [];
        foreach ($requirements as $table => $columns) {
            $actualColumns = Schema::getColumnListing($table);
            $absent = array_values(array_diff($columns, $actualColumns));
            if ($actualColumns === [] || $absent !== []) {
                $missing[$table] = $actualColumns === [] ? ['tableMissing'] : $absent;
            }
        }
        if ($missing !== []) {
            Log::error('Clinical storage schema is incomplete', [
                'operation' => static::class, 'missingSchema' => $missing,
            ]);
            throw new $exceptionClass(__($messageKey));
        }
    }

    /** Only an authoritative full import can reconcile an untracked status-changing submission. */
    protected function reconcileSubmissionFromResource(Model $record, array $resource, string $statusPath = 'status'): void
    {
        if (($resource['id'] ?? null) !== $record->uuid) {
            return;
        }
        $remoteStatus = data_get($resource, $statusPath);
        $links = $record->ehealthLinks()->whereStatus(
            JobStatus::PENDING->value
        )->whereNull('href')->with('job')->lockForUpdate()->get();
        foreach ($links as $link) {
            $request = $link->job->request_data;
            $expectedStatus = data_get($request, 'changes.status');
            $originalStatus = data_get($request, 'resource.'.$statusPath);
            // Qualify/use can leave status unchanged; a matching old status proves nothing about acceptance.
            if ($expectedStatus === null || $expectedStatus === $originalStatus || $remoteStatus !== $expectedStatus) {
                continue;
            }
            $status = JobStatus::PROCESSED->value;
            $link->job()->update(['status' => $status, 'response_data' => ['status' => 'processed', 'result' => $resource, 'reconciledBy' => 'fullResourceImport']]);
            $link->update(['status' => $status, 'error' => null, 'error_code' => null]);
            Log::info('Clinical submission reconciled from full resource', [
                'submissionId' => $link->id, 'resourceType' => $record::class, 'remoteStatus' => $remoteStatus,
            ]);
        }
    }

    /** Read the latest submission receipt without sending or repeating an ESOZ operation. */
    public function submissionState(Model $record): array
    {
        $link = $record->ehealthLinks()->whereEntity('job')->with('job')->latest('id')->first();
        $error = $link?->error;
        if (!is_array($error)) {
            $error = $link?->job?->response_data['error'] ?? null;
        }

        return [
            'id' => $link?->id,
            'status' => $link?->status,
            'error' => is_array($error) ? (new EHealthValidationException(['error' => $error]))->getFormattedMessage() : null,
        ];
    }

    protected function storageTransaction(callable $callback): mixed
    {
        try {
            return DB::transaction($callback);
        } catch (QueryException $exception) {
            throw ClinicalStorageException::fromQueryException($exception, static::class);
        }
    }

    // Add logical operators after implementing MongoDB, like Model|MongoModel
    // For now, it's only SQL models for the IDE to prompt
    public function __construct(protected Model $model)
    {
    }

    /**
     * Resolve the owner FK column and id for a patient (person or preperson).
     *
     * @param  Person|Preperson  $patient
     * @return array
     */
    protected function resolveOwner(Person|Preperson $patient): array
    {
        return $patient instanceof Preperson
            ? ['preperson_id', $patient->id]
            : ['person_id', $patient->id];
    }

    /**
     * Sync coding relationship using updateOrCreate (structure: {code, system}).
     * A missing value clears the relationship.
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
            return null;
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
     * A missing value clears the relationship.
     *
     * @param  mixed  $existing
     * @param  array|null  $newData
     * @param  string  $relationshipName
     * @return Model|null
     */
    protected function syncCodeableConcept(?Model $existing, ?array $newData, string $relationshipName): ?Model
    {
        if (empty($newData)) {
            return null;
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
        Repository::identifier()->update($identifier, $newData);
    }

    /**
     * Sync identifier relationship using updateOrCreate (structure: {identifier: {value, type: [codeableConcept]}}).
     * A missing value clears the relationship.
     *
     * @param  mixed  $existing
     * @param  array|null  $newData
     * @param  string  $relationshipName
     * @return Model|null
     */
    protected function syncIdentifier(?Model $existing, ?array $newData, string $relationshipName): ?Model
    {
        if (empty($newData)) {
            return null;
        }

        if ($existing && $existing->{$relationshipName}) {
            $identifier = $existing->{$relationshipName};
            $this->updateIdentifier($identifier, $newData);

            return $identifier;
        }

        $identifier = Repository::identifier()->store(
            $newData['identifier']['value'],
            ($newData['displayValue'] ?? ($newData['display_value'] ?? null))
        );
        if (isset($newData['identifier']['type'])) {
            Repository::codeableConcept()->attach($identifier, $newData);
        }

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
                        ($item['displayValue'] ?? ($item['display_value'] ?? null))
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
                    ($item['displayValue'] ?? ($item['display_value'] ?? null))
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
    protected function updateCodeableConcept(CodeableConcept $codeableConcept, array $newData): void
    {
        Repository::codeableConcept()->update($codeableConcept, $newData);
    }

    /**
     * Sync a BelongsToMany pivot, retaining positions for ordered clinical collections.
     *
     * @param  Model  $model
     * @param  string  $relation
     * @param  array  $newIds
     * @return void
     */
    protected function syncPivot(Model $model, string $relation, array $newIds): void
    {
        $relationship = $model->{$relation}();
        if (in_array('position', $relationship->getPivotColumns(), true)) {
            $positionedIds = [];
            foreach (array_values($newIds) as $position => $id) {
                $positionedIds[$id] = ['position' => $position];
            }
            $relationship->sync($positionedIds);
            $model->unsetRelation($relation);

            return;
        }
        $currentIds = $model->relationLoaded($relation)
            ? $model->{$relation}->pluck('id')->all()
            : $relationship->allRelatedIds()->all();
        $toDetach = array_diff($currentIds, $newIds);
        $toAttach = array_diff($newIds, $currentIds);
        if ($toDetach) {
            $relationship->detach($toDetach);
        }
        if ($toAttach) {
            $relationship->attach($toAttach);
        }
        $model->unsetRelation($relation);
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
