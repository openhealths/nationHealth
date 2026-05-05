<?php

namespace App\Repositories;

use App\Enums\Status;
use App\Models\LegalEntity;
use App\Models\LegalEntityType;

class LegalEntityRepository
{
    /**
     * Get all legal entities founded in the system.
     * Reformat it data to the array looks like:
     * [
     *  ['<uuid-1>', 'Legal Entity 1 Name']
     *  ['<uuid-2>', 'Legal Entity 2 Name']
     * ]
     *
     * @param array $legalEntityIds // Optional filter by specific legal entity IDs
     * @return array
     */
    public function getLegalEntitiesList(array $legalEntityIds = []): array
    {
        $typesById = LegalEntityType::pluck('name', 'id');

        // Get list of Legal Entities grouped by their name
        $legalEntityList = LegalEntity::listByFields()
            ->when(!empty($legalEntityIds), fn ($query) => $query->whereIn('id', $legalEntityIds))
            ->get()
            ->groupBy(fn ($item) => data_get($item, 'edr.name') ?: data_get($item, 'edr.public_name'))
            ->map(fn ($group) => $group->each->makeHidden(['edr'])) // Hide unnecessary fields
            ->toArray();

        $result = [];

        foreach (array_keys($legalEntityList) as $key) {
            // Count of Legal Entities with the same name
            $legalEntitiesCount = count($legalEntityList[$key]);

            foreach ($legalEntityList[$key] as $data) {
                $legalEntityTypeName = $typesById[$data['legalEntityTypeId']] ?? '';
                $name = $key;

                // If there are multiple Legal Entities with the same name - add Legal Entity Type to distinguish them
                if ($legalEntitiesCount > 1) {
                    $name .= " <{$legalEntityTypeName}>";
                }

                if ($data['status'] === Status::REORGANIZED->value) {
                    $name .= " (" . Status::REORGANIZED->value . ")";
                }

                $result[] = ['id' => $data['id'], 'uuid' => $data['uuid'], 'name' => $name];
            }
        }

        return $result;
    }

    /**
     * Save legators for the given legal entity.
     *
     * Deletes existing legators and inserts the new ones derived from the provided data.
     *
     * @param LegalEntity $legalEntity The legal entity to associate legators with.
     * @param array $data Array of legator data from the eHealth API response.
     *                    Each entry is expected to contain:
     *                    - merged_from_legal_entity (array): { uuid, name, edrpou }
     *                    - is_active (bool)
     *                    - reason (string)
     *                    - reason_date (string|null)
     *                    - type (string)
     *                    - ehealth_inserted_at (string)
     *                    - inserted_by (string)
     * @return void
     */
    public function saveLegators(LegalEntity $legalEntity, array $data): void
    {
        $legalEntityId = $legalEntity->id;

        $legatorsData= [];

        foreach ($data as $legator) {
            $legatorsData[] = [
                'legal_entity_id' => $legalEntityId,
                'uuid' => $legator['merged_from_legal_entity']['uuid'],
                'name' => $legator['merged_from_legal_entity']['name'],
                'is_active' => $legator['is_active'],
                'reason' => $legator['reason'],
                'reason_date' => $legator['reason_date'] ?? null,
                'edrpou' => $legator['merged_from_legal_entity']['edrpou'],
                "type" => $legator['type'],
                "ehealth_inserted_at" => $legator['ehealth_inserted_at'],
                "inserted_by" => $legator['inserted_by'],
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        if (!empty($legatorsData)) {
            $legalEntity->legators()->upsert(
                $legatorsData,
                ['uuid', 'legal_entity_id'], // unique keys
                ['edrpou', 'name', 'is_active', 'type', 'reason', 'reason_date', 'ehealth_inserted_at', 'inserted_by', 'updated_at'] // fields to update if record exists
            );
        }
    }
}
