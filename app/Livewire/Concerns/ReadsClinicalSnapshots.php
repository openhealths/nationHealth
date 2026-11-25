<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

use App\Classes\eHealth\EHealth;
use Illuminate\Support\Facades\Validator;

trait ReadsClinicalSnapshots
{
    protected function fetchCarePlanDetails(string $patientUuid, string $planUuid): array
    {
        return EHealth::carePlan()->getDetails($patientUuid, $planUuid)->validate()->toArray();
    }

    protected function fetchCarePlanCancelSnapshot(string $patientUuid, string $planUuid): array
    {
        $snapshot = $this->fetchCarePlanDetails($patientUuid, $planUuid);
        Validator::make($snapshot, ['id' => ['required', 'in:'.$planUuid]])->validate();

        return $snapshot;
    }

    protected function fetchActivityCancelSnapshot(string $patientUuid, string $planUuid, string $activityUuid): array
    {
        $snapshot = EHealth::carePlanActivity()->getDetails($patientUuid, $planUuid, $activityUuid, preserveApiKeys: true)->validate();
        if (array_is_list($snapshot) && count($snapshot) === 1) {
            $snapshot = $snapshot[0];
        }
        Validator::make($snapshot, ['id' => ['required', 'in:'.$activityUuid], 'detail' => ['required', 'array']])->validate();

        return $snapshot;
    }

    protected function fetchServiceRequestSnapshot(string $patientUuid, string $requestUuid): array
    {
        $snapshot = EHealth::serviceRequest()->getById($patientUuid, $requestUuid, preserveApiKeys: true)->validate();
        if (array_is_list($snapshot) && count($snapshot) === 1) {
            $snapshot = $snapshot[0];
        }
        Validator::make($snapshot, [
            'id' => ['required', 'in:'.$requestUuid],
            'status' => ['required', 'in:active'],
            'subject.identifier.value' => ['sometimes', 'required', 'in:'.$patientUuid],
        ])->validate();

        return $snapshot;
    }
}
