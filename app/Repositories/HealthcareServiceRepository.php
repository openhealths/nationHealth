<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Classes\eHealth\Api\Responses\HealthcareServiceResponse;
use App\Repositories\MedicalEvents\Repository;
use App\Models\HealthcareService;
use App\Models\LegalEntity;
use App\Dto\HealthcareService\Model as HealthcareServiceData;
use Illuminate\Support\Facades\DB;
use Throwable;

class HealthcareServiceRepository
{
    /**
     * Save a mapped healthcare service with its category and type, creating or updating them.
     *
     * @param  HealthcareServiceData  $data
     * @param  HealthcareService  $healthcareService
     * @param  LegalEntity  $legalEntity
     * @return HealthcareService
     * @throws Throwable
     */
    public function saveMapped(
        HealthcareServiceData $data,
        HealthcareService $healthcareService,
        LegalEntity $legalEntity
    ): HealthcareService {
        return DB::transaction(static function () use ($data, $healthcareService, $legalEntity): HealthcareService {
            $data->toModel($healthcareService);
            $healthcareService->legalEntity()->associate($legalEntity);

            if ($healthcareService->category) {
                Repository::codeableConcept()->update($healthcareService->category, $data->category);
            } else {
                $healthcareService->category()->associate(Repository::codeableConcept()->store($data->category));
            }

            $removedType = null;
            if ($data->type && $healthcareService->type) {
                Repository::codeableConcept()->update($healthcareService->type, $data->type);
            } elseif ($data->type) {
                $healthcareService->type()->associate(Repository::codeableConcept()->store($data->type));
            } elseif ($healthcareService->type) {
                $removedType = $healthcareService->type;
                $healthcareService->type()->dissociate();
            }

            $healthcareService->saveOrFail();

            if ($removedType) {
                Repository::codeableConcept()->delete($removedType);
            }

            return $healthcareService;
        });
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

            $prepared = collect($items)->map(static function (array $item) use ($existingConceptIds): array {
                $data = HealthcareServiceData::fromSource(new HealthcareServiceResponse($item));
                $existing = $existingConceptIds->get($data->uuid);

                // Casts on the model encode JSON columns and format eHealth dates
                $row = $data->toModel()->getAttributes();
                $row['division_id'] = $item['division_id'];
                $row['legal_entity_id'] = $item['legal_entity_id'];

                $row['category_id'] = $existing && $existing->categoryId
                    ? Repository::codeableConcept()->updateById($existing->categoryId, $data->category)->id
                    : Repository::codeableConcept()->store($data->category)->id;

                if ($data->type) {
                    $row['type_id'] = $existing && $existing->typeId
                        ? Repository::codeableConcept()->updateById($existing->typeId, $data->type)->id
                        : Repository::codeableConcept()->store($data->type)->id;
                } else {
                    $row['type_id'] = $existing->typeId ?? null;
                }

                return $row;
            })->values()->all();

            HealthcareService::upsert($prepared, 'uuid', new HealthcareService()->getFillable());
        });
    }
}
