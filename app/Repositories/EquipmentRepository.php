<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Classes\eHealth\Api\Responses\Collections\EquipmentDetails;
use App\Classes\eHealth\Api\Responses\Collections\EquipmentGetMany;
use App\Dto\Equipment\Model as EquipmentData;
use App\Models\Division;
use App\Models\Employee\Employee;
use App\Models\Equipment;
use App\Models\EquipmentName;
use App\Models\LegalEntity;
use Illuminate\Support\Facades\DB;
use Throwable;
use UnexpectedValueException;

class EquipmentRepository
{
    /**
     * Create or update equipment with its relationships and names.
     *
     * @param  EquipmentData  $data
     * @param  Equipment  $equipment
     * @return Equipment
     * @throws Throwable
     */
    public function save(EquipmentData $data, Equipment $equipment): Equipment
    {
        return DB::transaction(function () use ($data, $equipment): Equipment {
            $data->toModel($equipment);
            $this->setRelationshipIds($data, $equipment);
            $equipment->saveOrFail();

            $equipment->names()->delete();
            $equipment->names()->createMany($data->names);

            return $equipment;
        });
    }

    /**
     * Resolve equipment relationship UUIDs to local database IDs.
     *
     * @param  EquipmentData  $data
     * @param  Equipment  $equipment
     * @return void
     */
    private function setRelationshipIds(EquipmentData $data, Equipment $equipment): void
    {
        if ($data->legalEntityId !== null) {
            $equipment->legalEntityId = LegalEntity::whereUuid($data->legalEntityId)->value('id');
        }

        $equipment->divisionId = $data->divisionId === null ? null : Division::whereUuid($data->divisionId)->value('id');
        $equipment->parentId = $data->parentId === null ? null : Equipment::whereUuid($data->parentId)->value('id');
        $equipment->recorder = $data->recorder === null ? null : Employee::whereUuid($data->recorder)->value('id');
    }

    /**
     * Update equipment data after updating status.
     *
     * @param  EquipmentDetails  $response
     * @return void
     */
    public function updateStatus(EquipmentDetails $response): void
    {
        Equipment::whereUuid($response->get('id'))->update([
            'status' => $response->get('status'),
            'availability_status' => $response->get('availability_status'),
            'error_reason' => $response->get('error_reason'),
            'ehealth_updated_at' => $response->get('updated_at'),
            'ehealth_updated_by' => $response->get('updated_by'),
        ]);
    }

    /**
     * Sync equipment and related names.
     *
     * @param  EquipmentGetMany  $responses
     * @return void
     * @throws Throwable
     */
    public function sync(EquipmentGetMany $responses): void
    {
        if ($responses->isEmpty()) {
            return;
        }

        DB::transaction(static function () use ($responses): void {
            $items = $responses->toBase();

            $legalEntityIds = LegalEntity::whereIn(
                'uuid',
                $items->pluck('legal_entity_id')->filter()->unique()
            )->pluck('id', 'uuid');

            $divisionIds = Division::whereIn(
                'uuid',
                $items->pluck('division_id')->filter()->unique()
            )->pluck('id', 'uuid');

            $parentIds = Equipment::whereIn(
                'uuid',
                $items->pluck('parent_id')->filter()->unique()
            )->pluck('id', 'uuid');

            $recorderIds = Employee::whereIn(
                'uuid',
                $items->pluck('recorder')->filter()->unique()
            )->pluck('id', 'uuid');

            $rows = $items->map(static function (EquipmentDetails $response) use (
                $legalEntityIds,
                $divisionIds,
                $parentIds,
                $recorderIds
            ): array {
                if (!isset($legalEntityIds[$response->get('legal_entity_id')])) {
                    throw new UnexpectedValueException(
                        'Unknown legal entity UUID for equipment: ' . $response->get('legal_entity_id')
                    );
                }

                $data = objectMapper()->map($response, EquipmentData::class);
                $row = $data->toModel()->getAttributes();

                $row['legal_entity_id'] = $legalEntityIds[$response->get('legal_entity_id')];
                $row['division_id'] = $divisionIds[$response->get('division_id')] ?? null;
                $row['parent_id'] = $response->get('parent_id') ? ($parentIds[$response->get('parent_id')] ?? null) : null;
                $row['recorder'] = $recorderIds[$response->get('recorder')] ?? null;

                return $row;
            })->all();

            Equipment::upsert(
                $rows,
                'uuid',
                array_values(array_diff(new Equipment()->getFillable(), ['uuid']))
            );

            $equipments = Equipment::whereIn('uuid', $items->pluck('id'))
                ->get(['id', 'uuid'])
                ->keyBy('uuid');

            EquipmentName::whereIn('equipment_id', $equipments->pluck('id'))->delete();

            $names = $items->flatMap(
                static function (EquipmentDetails $response) use ($equipments): array {
                    $id = $equipments->get($response->get('id'))?->id;

                    if (!$id) {
                        return [];
                    }

                    return array_map(static fn (array $name): array => [
                        'equipment_id' => $id,
                        'name' => $name['name'],
                        'type' => $name['type'],
                    ], $response->get('names'));
                }
            )->all();

            if ($names !== []) {
                EquipmentName::insert($names);
            }
        });
    }
}
