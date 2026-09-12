<?php

declare(strict_types=1);

namespace App\Repositories\MedicalEvents;

use App\Models\MedicalEvents\Sql\DeviceDispense;
use App\Models\MedicalEvents\Sql\Quantity;
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
                $basedOn = isset($datum['basedOn'])
                    ? $this->storeIdentifier($datum['basedOn'])
                    : null;
                $performer = $this->storeIdentifier($datum['performer']);
                $location = $this->storeIdentifier($datum['location']);
                $performerLegalEntity = isset($datum['performerLegalEntity'])
                    ? $this->storeIdentifier($datum['performerLegalEntity'])
                    : null;
                $program = isset($datum['program'])
                    ? $this->storeIdentifier($datum['program'])
                    : null;
                $partOf = isset($datum['partOf'])
                    ? $this->storeIdentifier($datum['partOf'])
                    : null;
                $encounter = $this->storeIdentifier($datum['encounter']);

                $deviceDispense = $this->model->create([
                    'uuid' => $datum['uuid'] ?? $datum['id'],
                    $ownerColumn => $ownerId,
                    'based_on_id' => $basedOn?->id,
                    'status' => $datum['status'],
                    'performer_id' => $performer->id,
                    'location_id' => $location->id,
                    'when_handed_over' => $datum['whenHandedOver'],
                    'note' => $datum['note'] ?? null,
                    'performer_legal_entity_id' => $performerLegalEntity?->id,
                    'program_id' => $program?->id,
                    'part_of_id' => $partOf?->id,
                    'encounter_id' => $encounter->id,
                    'context_episode_id' => $datum['contextEpisodeId'] ?? null,
                    'origin_episode_id' => $datum['originEpisodeId'] ?? null,
                    'status_reason_id' => isset($datum['statusReason'])
                        ? Repository::codeableConcept()->store($datum['statusReason'])->id
                        : null,
                    'explanatory_letter' => $datum['explanatoryLetter'] ?? null,
                    'ehealth_inserted_at' => $datum['ehealthInsertedAt'] ?? null,
                    'ehealth_updated_at' => $datum['ehealthUpdatedAt'] ?? null
                ]);

                foreach ($datum['details'] as $detail) {
                    $device = isset($detail['device'])
                        ? $this->storeIdentifier($detail['device'])
                        : null;
                    $programDevice = isset($detail['programDevice'])
                        ? $this->storeIdentifier($detail['programDevice'])
                        : null;
                    $quantity = Quantity::create($detail['quantity']);

                    $deviceDispense->details()->create([
                        'device_id' => $device?->id,
                        'device_code_id' => isset($detail['deviceCode'])
                            ? Repository::codeableConcept()->store($detail['deviceCode'])->id
                            : null,
                        'program_device_id' => $programDevice?->id,
                        'quantity_id' => $quantity->id,
                        'sell_price' => $detail['sellPrice'] ?? null,
                        'reimbursement_amount' => $detail['reimbursementAmount'] ?? null,
                        'discount_amount' => $detail['discountAmount'] ?? null
                    ]);
                }

                if (isset($datum['supportingInfo'])) {
                    foreach ($datum['supportingInfo'] as $supporting) {
                        $identifier = $this->storeIdentifier($supporting);

                        $deviceDispense->supportingInfo()->attach($identifier->id);
                    }
                }
            }
        });
    }

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
            $existingDeviceDispenses = $this->model
                ->whereIn('uuid', collect($validatedData)->pluck('uuid')->toArray())
                ->withAllRelations()
                ->get()
                ->keyBy('uuid');

            foreach ($validatedData as $data) {
                $existing = $existingDeviceDispenses->get($data['uuid']);

                $basedOn = isset($data['based_on'])
                    ? $this->syncIdentifier($existing, $data['based_on'], 'basedOn')
                    : null;
                $performer = $this->syncIdentifier($existing, $data['performer'], 'performer');
                $location = $this->syncIdentifier($existing, $data['location'], 'location');
                $performerLegalEntity = isset($data['performer_legal_entity'])
                    ? $this->syncIdentifier($existing, $data['performer_legal_entity'], 'performerLegalEntity')
                    : null;
                $program = isset($data['program'])
                    ? $this->syncIdentifier($existing, $data['program'], 'program')
                    : null;
                $partOf = isset($data['part_of'])
                    ? $this->syncIdentifier($existing, $data['part_of'], 'partOf')
                    : null;
                $encounter = $this->syncIdentifier($existing, $data['encounter'], 'encounter');
                $statusReason = isset($data['status_reason'])
                    ? $this->syncCodeableConcept($existing, $data['status_reason'], 'statusReason')
                    : null;

                $deviceDispenseData = [
                    $ownerColumn => $ownerId,
                    'based_on_id' => $basedOn?->id,
                    'status' => $data['status'],
                    'performer_id' => $performer->id,
                    'location_id' => $location->id,
                    'when_handed_over' => $data['when_handed_over'],
                    'note' => $data['note'] ?? null,
                    'performer_legal_entity_id' => $performerLegalEntity?->id,
                    'program_id' => $program?->id,
                    'part_of_id' => $partOf?->id,
                    'encounter_id' => $encounter->id,
                    'context_episode_id' => $data['context_episode_id'] ?? null,
                    'origin_episode_id' => $data['origin_episode_id'] ?? null,
                    'status_reason_id' => $statusReason?->id,
                    'explanatory_letter' => $data['explanatory_letter'] ?? null,
                    'ehealth_inserted_at' => $data['ehealth_inserted_at'] ?? null,
                    'ehealth_updated_at' => $data['ehealth_updated_at'] ?? null
                ];

                if ($existing) {
                    $existing->update($deviceDispenseData);
                    $deviceDispense = $existing;
                } else {
                    $deviceDispense = $this->model->create(
                        array_merge(['uuid' => $data['uuid']], $deviceDispenseData)
                    );
                }

                $this->syncDetails($deviceDispense, $data['details']);

                $this->syncPivot(
                    $deviceDispense,
                    'supportingInfo',
                    $this->syncIdentifiers(
                        $existing,
                        $data['supporting_info'] ?? [],
                        'supportingInfo'
                    )
                );
            }
        });
    }

    /**
     * Sync device dispense details.
     *
     * @param  DeviceDispense  $deviceDispense
     * @param  array  $details
     * @return void
     */
    private function syncDetails(DeviceDispense $deviceDispense, array $details): void
    {
        $existingDetails = $deviceDispense->relationLoaded('details') ? $deviceDispense->details : collect();

        foreach ($details as $index => $detail) {
            $existingDetail = $existingDetails[$index] ?? null;

            $device = isset($detail['device']) ? $this->syncIdentifier($existingDetail, $detail['device'], 'device') : null;
            $deviceCode = isset($detail['device_code']) ? $this->syncCodeableConcept($existingDetail, $detail['device_code'], 'deviceCode') : null;
            $programDevice = isset($detail['program_device']) ? $this->syncIdentifier($existingDetail, $detail['program_device'], 'programDevice') : null;
            $quantity = $this->syncQuantity($existingDetail?->quantity, $detail['quantity']);

            $detailData = [
                'device_id' => $device?->id,
                'device_code_id' => $deviceCode?->id,
                'program_device_id' => $programDevice?->id,
                'quantity_id' => $quantity->id,
                'sell_price' => $detail['sell_price'] ?? null,
                'reimbursement_amount' => $detail['reimbursement_amount'] ?? null,
                'discount_amount' => $detail['discount_amount'] ?? null
            ];

            if ($existingDetail) {
                $existingDetail->update($detailData);

                continue;
            }

            $deviceDispense->details()->create($detailData);
        }

        foreach ($existingDetails->slice(count($details)) as $extra) {
            $quantity = $extra->quantity;
            $extra->delete();
            $quantity?->delete();
        }
    }

    /**
     * Update quantity or create a new one.
     *
     * @param  Quantity|null  $quantity
     * @param  array  $data
     * @return Quantity
     */
    private function syncQuantity(?Quantity $quantity, array $data): Quantity
    {
        if ($quantity) {
            $quantity->update($data);

            return $quantity;
        }

        return Quantity::create($data);
    }

    private function storeIdentifier(array $data)
    {
        $identifier = Repository::identifier()->store(
            $data['identifier']['value'],
            $data['display_value'] ?? $data['displayValue'] ?? null
        );
        Repository::codeableConcept()->attach($identifier, $data);

        return $identifier;
    }
}