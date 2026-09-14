<?php

declare(strict_types=1);

namespace App\Repositories\MedicalEvents;

use App\Models\MedicalEvents\Sql\DeviceDispense;
use App\Models\Person\Person;
use App\Models\Preperson;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * @property DeviceDispense $model
 */
class DeviceDispenseRepository extends BaseRepository
{
    /**
     * Store device dispenses in DB.
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
                $context = $this->storeIdentifier($datum['encounter']);
                $performer = $this->storeIdentifier($datum['performer']);
                $location = $this->storeIdentifier($datum['location']);
                $basedOn = isset($datum['basedOn'][0]) ? $this->storeIdentifier($datum['basedOn'][0]) : null;
                $partOf = isset($datum['partOf']) ? $this->storeIdentifier($datum['partOf']) : null;

                $deviceCode = isset($datum['details']['deviceCode'][0]['code'])
                    ? Repository::codeableConcept()->store($datum['details']['deviceCode'][0]['code'])
                    : null;
                $deviceDefinition = isset($datum['details']['device'][0]['deviceDefinition'])
                    ? $this->storeIdentifier($datum['details']['device'][0]['deviceDefinition'])
                    : null;

                $deviceDispense = $this->model->create([
                    'uuid' => $datum['uuid'] ?? $datum['id'],
                    $ownerColumn => $ownerId,
                    'based_on_id' => $basedOn?->id,
                    'part_of_id' => $partOf?->id,
                    'performer_id' => $performer->id,
                    'location_id' => $location->id,
                    'when_handed_over' => $datum['whenHandedOver'],
                    'quantity' => $datum['details']['quantity'],
                    'device_code_id' => $deviceCode?->id,
                    'device_definition_id' => $deviceDefinition?->id,
                    'context_id' => $context->id
                ]);

                foreach ($datum['supportingInfo'] ?? [] as $reference) {
                    $deviceDispense->supportingInfo()->attach($this->storeIdentifier($reference)->id);
                }
            }
        });
    }

    /**
     * Get device dispenses that are related to the encounter.
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
     * Sync device dispenses and their related data.
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
            $existingDispenses = $this->model
                ->whereIn('uuid', collect($validatedData)->pluck('uuid')->toArray())
                ->withAllRelations()
                ->get()
                ->keyBy('uuid');

            foreach ($validatedData as $data) {
                $existing = $existingDispenses->get($data['uuid']);

                $context = $this->syncIdentifier($existing, $data['encounter'], 'context');
                $performer = $this->syncIdentifier($existing, $data['performer'], 'performer');
                $location = $this->syncIdentifier($existing, $data['location'], 'location');
                $basedOn = $this->syncIdentifier($existing, $data['based_on'][0] ?? null, 'basedOn');
                $partOf = $this->syncIdentifier($existing, $data['part_of'] ?? null, 'partOf');
                $deviceCode = $this->syncCodeableConcept(
                    $existing,
                    $data['details']['device_code'][0]['code'] ?? null,
                    'deviceCode'
                );
                $deviceDefinition = $this->syncIdentifier(
                    $existing,
                    $data['details']['device'][0]['device_definition'] ?? null,
                    'deviceDefinition'
                );

                // A dispense names the device one way or the other, so the representation it no longer
                // carries is cleared instead of being left pointing at the previous choice
                $namesDeviceDefinition = isset($data['details']['device'][0]['device_definition']);

                $dispenseData = [
                    $ownerColumn => $ownerId,
                    'status' => $data['status'] ?? $existing?->status,
                    'based_on_id' => $basedOn?->id,
                    'part_of_id' => $partOf?->id,
                    'performer_id' => $performer->id,
                    'location_id' => $location->id,
                    'when_handed_over' => $data['when_handed_over'],
                    'quantity' => $data['details']['quantity'],
                    'device_code_id' => $namesDeviceDefinition ? null : $deviceCode?->id,
                    'device_definition_id' => $namesDeviceDefinition ? $deviceDefinition?->id : null,
                    'context_id' => $context->id,
                    'ehealth_inserted_at' => $data['ehealth_inserted_at'] ?? null,
                    'ehealth_updated_at' => $data['ehealth_updated_at'] ?? null
                ];

                if ($existing) {
                    $existing->update(array_filter(
                        $dispenseData,
                        static fn (mixed $value, string $key): bool => $value !== null
                            || in_array($key, ['based_on_id', 'part_of_id', 'device_code_id', 'device_definition_id'], true),
                        ARRAY_FILTER_USE_BOTH
                    ));
                    $deviceDispense = $existing;
                } else {
                    $deviceDispense = $this->model->create(array_merge(['uuid' => $data['uuid']], $dispenseData));
                }

                $this->syncPivot(
                    $deviceDispense,
                    'supportingInfo',
                    $this->syncIdentifiers($existing, $data['supporting_info'] ?? [], 'supportingInfo')
                );
            }
        });
    }

    /**
     * Store an identifier together with the codeable concept naming the resource it points at.
     *
     * @param  array  $reference
     * @return \App\Models\MedicalEvents\Sql\Identifier
     */
    private function storeIdentifier(array $reference)
    {
        $identifier = Repository::identifier()->store($reference['identifier']['value']);
        Repository::codeableConcept()->attach($identifier, $reference);

        return $identifier;
    }
}
