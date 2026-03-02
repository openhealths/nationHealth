<?php

declare(strict_types=1);

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
     * @param  array  $legalEntityIds  // Optional filter by specific legal entity IDs
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
}
