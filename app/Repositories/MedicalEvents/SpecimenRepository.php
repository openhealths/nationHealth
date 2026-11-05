<?php

declare(strict_types=1);

namespace App\Repositories\MedicalEvents;

use App\Core\Arr;
use App\Models\MedicalEvents\Sql\Specimen;
use App\Models\MedicalEvents\Sql\SpecimenCollection;
use App\Models\Person\Person;
use App\Models\Preperson;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * @property Specimen $model
 */
class SpecimenRepository extends BaseRepository
{
    /**
     * Store specimens of a submitted encounter package in DB.
     *
     * @param  array  $data
     * @param  Person|Preperson  $patient
     * @return void
     * @throws Throwable
     */
    public function store(array $data, Person|Preperson $patient): void
    {
        $this->sync(
            $patient,
            array_map(
                static fn (array $specimen): array => Arr::toSnakeCase(
                    collect($specimen)->put('uuid', $specimen['id'])->forget('id')->all()
                ),
                $data
            )
        );
    }

    /**
     * Get specimens created within the encounter.
     *
     * @param  string  $encounterUuid
     * @return array
     */
    public function get(string $encounterUuid): array
    {
        return $this->model
            ->withAllRelations()
            ->forEncounter($encounterUuid)
            ->get()
            ->toArray();
    }

    /**
     * Sync specimens and their related data.
     *
     * @param  Person|Preperson  $patient
     * @param  array  $validatedData
     * @return void
     * @throws Throwable
     */
    public function sync(Person|Preperson $patient, array $validatedData): void
    {
        [$ownerColumn, $ownerId] = $this->resolveOwner($patient);

        DB::transaction(function () use ($ownerColumn, $ownerId, $validatedData) {
            $existingSpecimens = $this->model
                ->whereIn('uuid', collect($validatedData)->pluck('uuid')->toArray())
                ->withAllRelations()
                ->get()
                ->keyBy('uuid');

            foreach ($validatedData as $data) {
                $existing = $existingSpecimens->get($data['uuid']);

                $condition = isset($data['condition'])
                    ? $this->syncCodeableConcept($existing, $data['condition'], 'condition')
                    : null;
                $statusReason = isset($data['status_reason'])
                    ? $this->syncCodeableConcept($existing, $data['status_reason'], 'statusReason')
                    : null;
                $context = isset($data['context'])
                    ? $this->syncIdentifier($existing, $data['context'], 'context')
                    : null;

                $specimenData = [
                    $ownerColumn => $ownerId,
                    'accession_identifier' => $data['accession_identifier'] ?? null,
                    'status' => $data['status'],
                    'type_id' => $this->syncCodeableConcept($existing, $data['type'], 'type')->id,
                    'condition_id' => $condition?->id,
                    'note' => $data['note'] ?? null,
                    'managing_organization_id' => $this->syncIdentifier(
                        $existing,
                        $data['managing_organization'],
                        'managingOrganization'
                    )->id,
                    'registered_by_id' => $this->syncIdentifier($existing, $data['registered_by'], 'registeredBy')->id,
                    'context_id' => $context?->id,
                    'received_time' => $data['received_time'] ?? null,
                    'status_reason_id' => $statusReason?->id,
                    'explanatory_letter' => $data['explanatory_letter'] ?? null,
                    'ehealth_inserted_at' => $data['ehealth_inserted_at'] ?? null,
                    'ehealth_inserted_by' => $data['ehealth_inserted_by'] ?? null,
                    'ehealth_updated_at' => $data['ehealth_updated_at'] ?? null,
                    'ehealth_updated_by' => $data['ehealth_updated_by'] ?? null
                ];

                if ($existing) {
                    $existing->update($specimenData);
                    $specimen = $existing;
                } else {
                    $specimen = $this->model->create(array_merge(['uuid' => $data['uuid']], $specimenData));
                }

                $this->syncPivot(
                    $specimen,
                    'parent',
                    $this->syncIdentifiers($existing, $data['parent'] ?? [], 'parent')
                );
                $this->syncPivot(
                    $specimen,
                    'request',
                    $this->syncIdentifiers($existing, $data['request'] ?? [], 'request')
                );
                $this->syncCollection($specimen, $data['collection']);
                $this->syncContainers($specimen, $data['container']);
            }
        });
    }

    /**
     * Sync collection details of the specimen.
     *
     * @param  Specimen  $specimen
     * @param  array  $collection
     * @return void
     */
    private function syncCollection(Specimen $specimen, array $collection): void
    {
        $existingCollection = $specimen->wasRecentlyCreated ? null : $specimen->collection;

        $procedure = isset($collection['procedure'])
            ? $this->syncIdentifier($existingCollection, $collection['procedure'], 'procedure')
            : null;
        $method = isset($collection['method'])
            ? $this->syncCodeableConcept($existingCollection, $collection['method'], 'method')
            : null;
        $bodySite = isset($collection['body_site'])
            ? $this->syncCodeableConcept($existingCollection, $collection['body_site'], 'bodySite')
            : null;
        $fastingStatus = isset($collection['fasting_status_codeable_concept'])
            ? $this->syncCodeableConcept(
                $existingCollection,
                $collection['fasting_status_codeable_concept'],
                'fastingStatusCodeableConcept'
            )
            : null;

        $collectionData = [
            'procedure_id' => $procedure?->id,
            'collector_id' => $this->syncIdentifier($existingCollection, $collection['collector'], 'collector')->id,
            'collected_date_time' => $collection['collected_date_time'] ?? null,
            'duration_id' => Repository::quantity()->sync($existingCollection?->duration, $collection['duration'] ?? null)?->id,
            'quantity_id' => Repository::quantity()->sync($existingCollection?->quantity, $collection['quantity'] ?? null)?->id,
            'method_id' => $method?->id,
            'body_site_id' => $bodySite?->id,
            'fasting_status_codeable_concept_id' => $fastingStatus?->id
        ];

        if ($existingCollection) {
            $existingCollection->update($collectionData);
            $specimenCollection = $existingCollection;
        } else {
            /** @var SpecimenCollection $specimenCollection */
            $specimenCollection = $specimen->collection()->create($collectionData);
        }

        Repository::period()->sync($specimenCollection, $collection['collected_period'] ?? [], 'collectedPeriod');
    }

    /**
     * Sync containers of the specimen.
     *
     * @param  Specimen  $specimen
     * @param  array  $containers
     * @return void
     */
    private function syncContainers(Specimen $specimen, array $containers): void
    {
        $existingContainers = $specimen->wasRecentlyCreated ? collect() : $specimen->container;

        foreach ($containers as $index => $container) {
            $existingContainer = $existingContainers[$index] ?? null;

            $type = isset($container['type'])
                ? $this->syncCodeableConcept($existingContainer, $container['type'], 'type')
                : null;
            $additive = isset($container['additive_codeable_concept'])
                ? $this->syncCodeableConcept(
                    $existingContainer,
                    $container['additive_codeable_concept'],
                    'additiveCodeableConcept'
                )
                : null;

            $containerData = [
                'identifier' => $container['identifier'],
                'description' => $container['description'] ?? null,
                'type_id' => $type?->id,
                'capacity_id' => Repository::quantity()->sync($existingContainer?->capacity, $container['capacity'] ?? null)?->id,
                'specimen_quantity_id' => Repository::quantity()->sync(
                    $existingContainer?->specimenQuantity,
                    $container['specimen_quantity'] ?? null
                )?->id,
                'additive_codeable_concept_id' => $additive?->id
            ];

            if ($existingContainer) {
                $existingContainer->update($containerData);

                continue;
            }

            $specimen->container()->create($containerData);
        }

        foreach ($existingContainers->slice(count($containers)) as $extraContainer) {
            $extraContainer->delete();
        }
    }
}
