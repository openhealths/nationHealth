<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Repositories\MedicalEvents\Repository;
use App\Models\HealthcareService;
use Carbon\Carbon;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

class HealthcareServiceRepository
{
    /**
     * Store data after successful creating in EHealth.
     *
     * @param  array  $data
     * @return HealthcareService
     * @throws Throwable
     */
    public function store(array $data): HealthcareService
    {
        return DB::transaction(function () use ($data) {
            $data = $this->storeCategoryAndType($data);

            return HealthcareService::create($data);
        });
    }

    /**
     * Update existed record with EHealth data.
     *
     * @param  array  $data
     * @param  bool  $updateCategoryAndType
     * @return HealthcareService
     * @throws Throwable
     */
    public function update(array $data, bool $updateCategoryAndType = true): HealthcareService
    {
        return DB::transaction(function () use ($data, $updateCategoryAndType) {
            if (empty($data['id'])) {
                throw new InvalidArgumentException('HealthcareService ID is required for update.');
            }

            $id = $data['id'];
            unset($data['id']); // remove, to avoid updating it

            if ($updateCategoryAndType) {
                $service = HealthcareService::with(['category.coding', 'type.coding'])->findOrFail($id);

                $data = $this->updateCategoryAndType($service, $data);
            } else {
                $service = HealthcareService::findOrFail($id);
            }

            $service->update($data);

            return $service;
        });
    }

    /**
     * Update healthcare service data after activation/deactivation.
     *
     * @param  string  $uuid
     * @param  array  $data
     * @return void
     */
    public function updateStatus(string $uuid, array $data): void
    {
        $forUpdate = Arr::only($data, ['status', 'ehealth_updated_at', 'ehealth_updated_by']);
        HealthcareService::whereUuid($uuid)->update($forUpdate);
    }

    /**
     * Sync data.
     *
     * @param  array  $items
     * @return void
     * @throws Throwable
     */
    public function sync(array $items): void
    {
        DB::transaction(static function () use ($items) {
            $uuids = collect($items)->pluck('uuid')->all();

            // Get the existing IDs of the category and type for updating them
            $existingConceptIds = HealthcareService::whereIn('uuid', $uuids)
                ->get(['uuid', 'category_id', 'type_id'])
                ->keyBy('uuid');

            $prepared = collect($items)->map(function (array $item) use ($existingConceptIds) {
                $existing = $existingConceptIds->get($item['uuid']);

                // Sync category
                $categoryConcept = $item['category'] ?? null;
                if ($categoryConcept) {
                    if ($existing && $existing->categoryId) {
                        $item['category_id'] = Repository::codeableConcept()->updateById(
                            $existing->categoryId,
                            $categoryConcept
                        )->id;
                    } else {
                        $item['category_id'] = Repository::codeableConcept()->store($categoryConcept)->id;
                    }
                }

                // Sync type
                $typeConcept = $item['type'] ?? null;
                if ($typeConcept) {
                    if ($existing && $existing->typeId) {
                        $item['type_id'] = Repository::codeableConcept()->updateById(
                            $existing->typeId,
                            $typeConcept
                        )->id;
                    } else {
                        $item['type_id'] = Repository::codeableConcept()->store($typeConcept)->id;
                    }
                }

                unset($item['category'], $item['type']);

                // Format to JSON
                $item['available_time'] = json_encode($item['available_time'] ?? [], JSON_THROW_ON_ERROR);
                $item['not_available'] = json_encode($item['not_available'] ?? [], JSON_THROW_ON_ERROR);

                $item['ehealth_inserted_at'] = Carbon::parse($item['ehealth_inserted_at'])->format('Y-m-d H:i:s');
                $item['ehealth_updated_at'] = Carbon::parse($item['ehealth_updated_at'])->format('Y-m-d H:i:s');

                return $item;
            })->values()->all();

            HealthcareService::upsert($prepared, 'uuid', new HealthcareService()->getFillable());
        });
    }

    /**
     * Store category and type in separate tables.
     *
     * @param  array  $data
     * @return array ID of created category and type.
     */
    protected function storeCategoryAndType(array $data): array
    {
        // Save category
        $category = Repository::codeableConcept()->store($data['category']);
        $data['category_id'] = $category->id;

        // Save if type is present
        if (!empty($data['type'])) {
            $type = Repository::codeableConcept()->store($data['type']);
            $data['type_id'] = $type->id;
        }

        // Remove nested data to avoid mass assignment issues
        unset($data['category'], $data['type']);

        return $data;
    }

    /**
     * Update category and type from edit form.
     *
     * @param  HealthcareService  $service
     * @param  array  $data
     * @return array
     */
    protected function updateCategoryAndType(HealthcareService $service, array $data): array
    {
        // Update category (it's required)
        if (!empty($data['category'])) {
            Repository::codeableConcept()->update($service->category, $data['category']);
        }

        // Handle type
        if (array_key_exists('type', $data)) {
            if (!empty($data['type'])) {
                // Update or create
                if ($service->type) {
                    Repository::codeableConcept()->update($service->type, $data['type']);
                } else {
                    $type = Repository::codeableConcept()->store($data['type']);
                    $data['type_id'] = $type->id;
                }
            } else {
                // If was presented before in draft, but then removed
                if ($service->type) {
                    // Dissociate and delete
                    $service->type()->dissociate();
                    $service->save();

                    Repository::codeableConcept()->delete($service->type);
                }

                $data['type_id'] = null;
            }
        }

        unset($data['category'], $data['type']);

        return $data;
    }
}
