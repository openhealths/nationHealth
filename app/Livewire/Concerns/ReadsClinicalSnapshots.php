<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

use App\Classes\eHealth\EHealth;
use Illuminate\Support\Facades\Validator;

trait ReadsClinicalSnapshots
{
    protected function fetchCarePlanDetails(string $patientUuid, string $planUuid): array
    {
        $data = EHealth::carePlan()->getDetails($patientUuid, $planUuid, preserveApiKeys: true)->validate();

        return array_is_list($data) && count($data) === 1 ? $data[0] : $data;
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
        $details = EHealth::serviceRequest()->getDetails($patientUuid, $requestUuid)->validate();
        $snapshot = $details->signaturePayload;
        Validator::make($snapshot, ['status' => 'required|in:active'])->validate();

        return $snapshot;
    }
}
