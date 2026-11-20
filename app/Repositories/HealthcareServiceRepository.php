<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Classes\eHealth\Api\Responses\Collections\HealthcareServiceDetails;
use App\Classes\eHealth\Api\Responses\Collections\HealthcareServiceGetMany;
use App\Dto\HealthcareService\Model as HealthcareServiceData;
use App\Models\HealthcareService;
use App\Models\LegalEntity;
use App\Models\MedicalEvents\Sql\CodeableConcept;
use App\Repositories\MedicalEvents\Repository;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

class HealthcareServiceRepository
{
    /**
     * Save a mapped healthcare service with its category and type, creating or updating them.
     *
     * @param  HealthcareServiceData  $data
     * @param  HealthcareService  $healthcareService
     * @return HealthcareService
     * @throws Throwable
     */
    public function save(HealthcareServiceData $data, HealthcareService $healthcareService): HealthcareService
    {
        return DB::transaction(static function () use ($data, $healthcareService): HealthcareService {
            $data->toModel($healthcareService);

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
     * Delete a draft healthcare service with its category and type.
     * The service is deleted first, since its foreign keys reference the codeable concepts.
     *
     * @param  HealthcareService  $healthcareService
     * @return void
     * @throws Throwable
     */
    public function deleteDraft(HealthcareService $healthcareService): void
    {
        DB::transaction(static function () use ($healthcareService): void {
            $category = $healthcareService->category;
            $type = $healthcareService->type;

            $healthcareService->delete();

            Repository::codeableConcept()->delete($category);

            if ($type) {
                Repository::codeableConcept()->delete($type);
            }
        });
    }

    /**
     * Sync healthcare services from the eHealth list of the legal entity, linking them to its divisions by uuid.
     * The list is requested with the legal entity's token, so every healthcare service in it belongs to that legal entity.
     *
     * @param  LegalEntity  $legalEntity
     * @param  HealthcareServiceGetMany  $healthcareServices
     * @return void
     * @throws Throwable
     */
    public function sync(LegalEntity $legalEntity, HealthcareServiceGetMany $healthcareServices): void
    {
        DB::transaction(function () use ($legalEntity, $healthcareServices): void {
            // A plain collection holds the mapped DTOs, since the list collection wraps every item it is created with
            $items = $healthcareServices->toBase()->map(
                static fn (HealthcareServiceDetails $details): HealthcareServiceData => objectMapper()->map($details, HealthcareServiceData::class)
            );

            $divisionIds = $legalEntity->divisions()
                ->whereIn('uuid', $items->pluck('divisionUuid')->unique())
                ->pluck('id', 'uuid');

            // Lock in a consistent order so overlapping sync pages read the latest category and type codings.
            /** @var Collection<string, HealthcareService> $existingServices */
            $existingServices = HealthcareService::with(['category.coding', 'type.coding'])
                ->whereIn('uuid', $items->pluck('uuid'))
                ->orderBy('uuid')
                ->lockForUpdate()
                ->get(['uuid', 'category_id', 'type_id'])
                ->keyBy('uuid');

            // The upsert bypasses model casts, so the row is taken from the model attributes, already cast for storage
            $rows = $items->map(fn (HealthcareServiceData $data): array => [
                ...$data->toModel()->getAttributes(),
                'division_id' => $divisionIds[$data->divisionUuid],
                'legal_entity_id' => $legalEntity->id,
                ...$this->storeCodeableConcepts($data, $existingServices->get($data->uuid))
            ])->all();

            HealthcareService::upsert($rows, 'uuid', new HealthcareService()->getFillable());

            // Deleted after the upsert, once no healthcare service references them
            $removedTypes = $items->filter(static fn (HealthcareServiceData $data): bool => $data->type === null)
                ->map(static fn (HealthcareServiceData $data): ?CodeableConcept => $existingServices->get($data->uuid)?->type)
                ->filter();

            foreach ($removedTypes as $removedType) {
                Repository::codeableConcept()->delete($removedType);
            }
        });
    }

    /**
     * Store or update the category and type of healthcare service as codeable concepts.
     *
     * @param  HealthcareServiceData  $data
     * @param  HealthcareService|null  $existing
     * @return array{category_id: int, type_id: int|null}
     */
    protected function storeCodeableConcepts(HealthcareServiceData $data, ?HealthcareService $existing): array
    {
        $category = $existing?->category;
        $categoryId = $category
            ? Repository::codeableConcept()->update($category, $data->category, $category->coding)->id
            : Repository::codeableConcept()->store($data->category)->id;

        $typeId = null;
        if ($data->type) {
            $type = $existing?->type;
            $typeId = $type
                ? Repository::codeableConcept()->update($type, $data->type, $type->coding)->id
                : Repository::codeableConcept()->store($data->type)->id;
        }

        return ['category_id' => $categoryId, 'type_id' => $typeId];
    }
}
