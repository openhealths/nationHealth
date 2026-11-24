<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Dto\CarePlan\Model as CarePlanData;
use App\Models\CarePlanRequest;
use App\Models\LegalEntity;
use App\Models\Person\Person;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class CarePlanRequestRepository
{
    /** Save prepared request fields, associating the local patient and organization. */
    public function create(Person $patient, LegalEntity $legalEntity, CarePlanData $data): CarePlanRequest
    {
        return DB::transaction(function () use ($patient, $legalEntity, $data): CarePlanRequest {
            $createPlanRequest = objectMapper()->map($data, CarePlanRequest::class);
            $createPlanRequest->person()->associate($patient);
            $createPlanRequest->legalEntityId = $legalEntity->id;
            $createPlanRequest->userId = Auth::id();
            $createPlanRequest->version = 1;
            $createPlanRequest->saveOrFail();

            return $createPlanRequest->refresh();
        });
    }

    /** Resolve and lock the expected editable revision before writing its prepared DTO. */
    public function updateRequest(CarePlanRequest $draft, CarePlanData $data): CarePlanRequest
    {
        return DB::transaction(function () use ($draft, $data): CarePlanRequest {
            $createPlanRequest = CarePlanRequest::forEditor(legalEntity()->id, Auth::id())
                ->editable()
                ->where('uuid', $draft->uuid)
                ->where('uuid', $data->uuid)
                ->where('person_id', $draft->personId)
                ->where('version', $data->version)
                ->lockForUpdate()
                ->findOrFail($draft->id);
            objectMapper()->map($data, $createPlanRequest);
            $createPlanRequest->version++;
            $createPlanRequest->saveOrFail();

            return $createPlanRequest->refresh();
        });
    }
}
