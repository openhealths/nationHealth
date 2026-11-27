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
use UnexpectedValueException;
use Throwable;

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
            objectMapper()->map($data, $equipment);

            $this->setRelationshipIds($data, $equipment);
            $equipment->saveOrFail();

            $equipment->names()->delete();
            $equipment->names()->createMany($data->names);

            return $equipment;
        });
    }

    /**
     * Update equipment statuses and eHealth update metadata.
     *
     * @param  EquipmentData  $data
     * @param  Equipment  $equipment
     * @return void
     * @throws Throwable
     */
    public function updateStatus(EquipmentData $data, Equipment $equipment): void
    {
        $equipment->fill([
            'status' => $data->status,
            'availability_status' => $data->availabilityStatus,
            'error_reason' => $data->errorReason,
            'ehealth_updated_at' => $data->ehealthUpdatedAt,
            'ehealth_updated_by' => $data->ehealthUpdatedBy
        ]);

        $equipment->saveOrFail();
    }

    /**
     * Sync equipment and related names for the legal entity.
     * The list is requested with the legal entity's token, so every equipment in it belongs to that legal entity.
     *
     * @param  LegalEntity  $legalEntity
     * @param  EquipmentGetMany  $equipments
     * @return void
     * @throws Throwable
     */
    public function sync(LegalEntity $legalEntity, EquipmentGetMany $equipments): void
    {
        if ($equipments->isEmpty()) {
            return;
        }

        DB::transaction(static function () use ($legalEntity, $equipments): void {
            $items = $equipments->toBase()->map(
                static fn (EquipmentDetails $details): EquipmentData => objectMapper()->map($details, EquipmentData::class)
            );

            $divisionIds = $legalEntity->divisions()
                ->whereIn('uuid', $items->pluck('divisionUuid')->filter()->unique())
                ->pluck('id', 'uuid');

            $recorderIds = Employee::whereIn(
                'uuid',
                $items->pluck('recorderUuid')->filter()->unique()
            )->pluck('id', 'uuid');

            $rows = $items->map(static function (EquipmentData $data) use (
                $legalEntity,
                $divisionIds,
                $recorderIds
            ): array {
                if ($data->divisionUuid !== null && !$divisionIds->has($data->divisionUuid)) {
                    throw new UnexpectedValueException("Division $data->divisionUuid is not found in the legal entity");
                }

                return [
                    ...objectMapper()->map($data, Equipment::class)->getAttributes(),
                    'legal_entity_id' => $legalEntity->id,
                    'division_id' => $data->divisionUuid === null ? null : $divisionIds[$data->divisionUuid],
                    'parent_id' => null,
                    'recorder' => $recorderIds[$data->recorderUuid] ?? null
                ];
            });

            Equipment::upsert(
                $rows->all(),
                'uuid',
                array_values(array_diff(new Equipment()->getFillable(), ['uuid', 'parent_id']))
            );

            // Resolve parent IDs after all equipment from the page has been stored.
            $parentIds = Equipment::whereIn(
                'uuid',
                $items->pluck('parentUuid')->filter()->unique()
            )->pluck('id', 'uuid');

            $parentRows = $items->map(
                static fn (EquipmentData $data, int $index): array => [
                    ...$rows[$index],
                    'parent_id' => $parentIds[$data->parentUuid] ?? null
                ]
            )->all();

            Equipment::upsert(
                $parentRows,
                'uuid',
                ['parent_id']
            );

            $equipmentIds = Equipment::whereIn(
                'uuid',
                $items->pluck('uuid')
            )->pluck('id', 'uuid');

            $names = $items->flatMap(
                static fn (EquipmentData $data): array => array_map(
                    static fn (array $name): array => [
                        ...$name,
                        'equipment_id' => $equipmentIds[$data->uuid]
                    ],
                    $data->names
                )
            )->all();

            EquipmentName::whereIn(
                'equipment_id',
                $equipmentIds->values()
            )->delete();

            if ($names !== []) {
                EquipmentName::insert($names);
            }
        });
    }

    /**
     * Set equipment relationship IDs.
     *
     * @param  EquipmentData  $data
     * @param  Equipment  $equipment
     * @return void
     */
    protected function setRelationshipIds(EquipmentData $data, Equipment $equipment): void
    {
        $divisionId = filled($data->divisionUuid)
            ? Division::where('legal_entity_id', $equipment->legalEntityId)
                ->whereUuid($data->divisionUuid)
                ->value('id')
            : null;

        if (filled($data->divisionUuid) && $divisionId === null) {
            throw new UnexpectedValueException("Division $data->divisionUuid is not found in the legal entity");
        }

        $equipment->divisionId = $divisionId;
        $parentId = filled($data->parentUuid) ? Equipment::whereUuid($data->parentUuid)->value('id') : null;

        if (filled($data->parentUuid) && $parentId === null) {
            throw new UnexpectedValueException("Parent equipment $data->parentUuid is not found");
        }

        $equipment->parentId = $parentId;
        $recorderId = filled($data->recorderUuid) ? Employee::whereUuid($data->recorderUuid)->value('id') : null;

        if ($recorderId === null) {
            throw new UnexpectedValueException("Recorder $data->recorderUuid is not found");
        }

        $equipment->recorder = $recorderId;
    }
}
