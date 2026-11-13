<?php

declare(strict_types=1);

namespace App\Repositories;

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
     * @param  HealthcareServiceData  ...$healthcareServices
     * @return void
     * @throws Throwable
     */
    public function sync(LegalEntity $legalEntity, HealthcareServiceData ...$healthcareServices): void
    {
        DB::transaction(function () use ($legalEntity, $healthcareServices): void {
            $items = collect($healthcareServices);

            $divisionIds = $legalEntity->divisions()
                ->whereIn('uuid', $items->pluck('divisionUuid')->unique())
                ->pluck('id', 'uuid');

            // Existing category and type are updated in place; a type that is no longer returned is removed
            /** @var Collection<string, HealthcareService> $existingServices */
            $existingServices = HealthcareService::with(['category.coding', 'type.coding'])
                ->whereIn('uuid', $items->pluck('uuid'))
                ->get(['uuid', 'category_id', 'type_id'])
                ->keyBy('uuid');

            $rows = $items->map(fn (HealthcareServiceData $data): array => $this->prepareRow(
                $data,
                $divisionIds[$data->divisionUuid],
                $legalEntity->id,
                $existingServices->get($data->uuid)
            ))->all();

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
     * Healthcare service attributes for the upsert; category and type are stored or updated as codeable concepts.
     *
     * @param  HealthcareServiceData  $data
     * @param  int  $divisionId
     * @param  int  $legalEntityId
     * @param  HealthcareService|null  $existing
     * @return array
     */
    protected function prepareRow(
        HealthcareServiceData $data,
        int $divisionId,
        int $legalEntityId,
        ?HealthcareService $existing
    ): array {
        $healthcareService = $data->toModel();
        $healthcareService->divisionId = $divisionId;
        $healthcareService->legalEntityId = $legalEntityId;

        $healthcareService->categoryId = $existing?->category
            ? Repository::codeableConcept()->update($existing->category, $data->category)->id
            : Repository::codeableConcept()->store($data->category)->id;

        if ($data->type) {
            $healthcareService->typeId = $existing?->type
                ? Repository::codeableConcept()->update($existing->type, $data->type)->id
                : Repository::codeableConcept()->store($data->type)->id;
        } else {
            $healthcareService->typeId = null;
        }

        // Casts on the model encode JSON columns and format eHealth dates
        return $healthcareService->getAttributes();
    }
}
