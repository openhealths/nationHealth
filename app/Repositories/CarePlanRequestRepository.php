<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\CarePlanRequest;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Persist applications prepared by the local care plan editor.
 */
class CarePlanRequestRepository
{
    /**
     * Insert the prepared request. A repeat submission from the same editor reuses the first saved row.
     *
     * @param  CarePlanRequest  $request  Mapped request with a stable UUID, patient, user and facility.
     * @return CarePlanRequest
     */
    public function create(CarePlanRequest $request): CarePlanRequest
    {
        $request->version = 1;

        try {
            $request->saveOrFail();
        } catch (UniqueConstraintViolationException $exception) {
            return CarePlanRequest::query()
                ->whereUuid($request->uuid)
                ->whereUserId($request->userId)
                ->whereLegalEntityId($request->legalEntityId)
                ->wherePersonId($request->personId)
                ->first() ?? throw $exception;
        }

        return $request;
    }
}
