<?php

declare(strict_types=1);

namespace App\Repositories\MedicalEvents;

use App\Models\MedicalEvents\Sql\DeviceAssociation;
use App\Models\Person\Person;
use App\Models\Preperson;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * @property DeviceAssociation $model
 */
class DeviceAssociationRepository extends BaseRepository
{
    /**
     * Store device associations in DB.
     *
     * @param  array  $data
     * @param  Person|Preperson  $patient
     * @return void
     * @throws Throwable
     */
    public function store(array $data, Person|Preperson $patient): void
    {
        [$ownerColumn, $ownerId] = $this->resolveOwner($patient);

        DB::transaction(function () use ($data, $ownerColumn, $ownerId) {
            foreach ($data as $datum) {
                $context = Repository::identifier()->store($datum['context']['identifier']['value']);
                Repository::codeableConcept()->attach($context, $datum['context']);

                $recorder = Repository::identifier()->store($datum['recorder']['identifier']['value']);
                Repository::codeableConcept()->attach($recorder, $datum['recorder']);

                $device = Repository::identifier()->store($datum['device']['identifier']['value']);
                Repository::codeableConcept()->attach($device, $datum['device']);

                $this->model->create([
                    'uuid' => $datum['uuid'] ?? $datum['id'],
                    $ownerColumn => $ownerId,
                    'device_id' => $device->id,
                    'status' => $datum['status'],
                    'body_site_id' => isset($datum['bodySite'])
                        ? Repository::codeableConcept()->store($datum['bodySite'])->id
                        : null,
                    'association_date' => $datum['associationDate'] ?? null,
                    'recorded' => $datum['recorded'],
                    'primary_source' => $datum['primarySource'],
                    'report_origin_id' => isset($datum['reportOrigin'])
                        ? Repository::codeableConcept()->store($datum['reportOrigin'])->id
                        : null,
                    'context_id' => $context->id,
                    'recorder_id' => $recorder->id
                ]);
            }
        });
    }

    /**
     * Get device associations that are related to the encounter.
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
     * Sync device associations and their related data.
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
            $existingAssociations = $this->model
                ->whereIn('uuid', collect($validatedData)->pluck('uuid')->toArray())
                ->withAllRelations()
                ->get()
                ->keyBy('uuid');

            foreach ($validatedData as $data) {
                $existing = $existingAssociations->get($data['uuid']);

                $device = $this->syncIdentifier($existing, $data['device'], 'device');
                $bodySite = $this->syncCodeableConcept($existing, $data['body_site'] ?? null, 'bodySite');
                $reportOrigin = $this->syncCodeableConcept($existing, $data['report_origin'] ?? null, 'reportOrigin');
                $context = $this->syncIdentifier($existing, $data['context'], 'context');
                $recorder = $this->syncIdentifier($existing, $data['recorder'], 'recorder');

                $associationData = [
                    $ownerColumn => $ownerId,
                    'device_id' => $device->id,
                    'status' => $data['status'],
                    'body_site_id' => $bodySite?->id,
                    'association_date' => $data['association_date'] ?? null,
                    'recorded' => $data['recorded'],
                    'primary_source' => $data['primary_source'],
                    'report_origin_id' => $reportOrigin?->id,
                    'context_id' => $context->id,
                    'recorder_id' => $recorder->id
                ];

                if ($existing) {
                    $existing->update($associationData);

                    continue;
                }

                $this->model->create(array_merge(['uuid' => $data['uuid']], $associationData));
            }
        });
    }
}
