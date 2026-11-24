<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\CarePlanRequest;
use App\Models\LegalEntity;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class CarePlanRequestRepository
{
    /** Persist the model prepared by the editor from the validated form and patient context. */
    public function create(CarePlanRequest $createPlanRequest): CarePlanRequest
    {
        $createPlanRequest->uuid ??= (string) Str::uuid();
        $createPlanRequest->version = 1;
        $createPlanRequest->saveOrFail();

        return $createPlanRequest;
    }

    /** Lock the version opened by the editor so stale forms cannot overwrite changes. */
    public function updateRequest(
        CarePlanRequest $createPlanRequest,
        LegalEntity $legalEntity,
        User $editor,
        int $version,
    ): CarePlanRequest {
        if ($createPlanRequest->isDirty(['id', 'uuid', 'user_id', 'legal_entity_id', 'person_id', 'care_plan_id'])) {
            throw new InvalidArgumentException('Cannot change the identity or ownership of a Care Plan request.');
        }

        return DB::transaction(static function () use ($createPlanRequest, $legalEntity, $editor, $version): CarePlanRequest {
            $revision = CarePlanRequest::forEditor($legalEntity->id, $editor->id)
                ->where('uuid', $createPlanRequest->uuid)
                ->where('person_id', $createPlanRequest->personId)
                ->where('version', $version)
                ->lockForUpdate()
                ->findOrFail($createPlanRequest->id);

            // Check submission state after locking, including links inserted while acquiring the lock.
            CarePlanRequest::editable()->whereKey($revision->id)->firstOrFail();

            $createPlanRequest->version = $revision->version + 1;
            $createPlanRequest->saveOrFail();

            return $createPlanRequest;
        });
    }
}
