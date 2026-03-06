<?php

declare(strict_types=1);

namespace App\Services\Dictionary\Collections;

use Illuminate\Support\Collection;

class ServiceCollection extends Collection
{
    /**
     * Get flattened service structure.
     *
     * Converts hierarchical service structure into a flat collection,
     * processing nested groups and services while filtering out inactive
     * elements and removing duplicates by service ID.
     *
     * @return self Flattened collection of unique active services
     */
    public function flattened(): self
    {
        return $this->flatMap(function (array $item) {
            return $this->flattenServiceItem($item);
        })->unique('id', true);
    }

    /**
     * Recursively flatten the structure of a service element.
     *
     * Processes nested service groups and services recursively,
     * filtering out inactive elements and normalizing the structure.
     * Handles both 'groups' and 'services' nested properties.
     *
     * @param  array  $item  Service item to flatten
     * @return Collection Collection of flattened service items
     */
    protected function flattenServiceItem(array $item): Collection
    {
        // Remove inactive elements
        if (isset($item['is_active']) && $item['is_active'] === false) {
            return collect();
        }

        $result = collect([
            [
                'code' => $item['code'],
                'name' => $item['name'],
                'id' => $item['id'],
                'category' => $item['category'] ?? null
            ]
        ]);

        // Process groups if they exist
        if (isset($item['groups'])) {
            $groupItems = collect($item['groups'])
                ->flatMap(fn (array $group) => $this->flattenServiceItem($group));
            $result = $result->merge($groupItems);
        }

        // Process services if they exist
        if (isset($item['services'])) {
            $serviceItems = collect($item['services'])
                ->flatMap(fn (array $service) => $this->flattenServiceItem($service));
            $result = $result->merge($serviceItems);
        }

        return $result;
    }
}
