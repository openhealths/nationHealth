<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\MedicalEvents\Activity;

use App\Classes\eHealth\EHealth;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Models\CarePlan;
use App\Models\CarePlanActivity;
use RuntimeException;

trait VerifiesCarePlanActivityRegistration
{
    protected function assertCarePlanActivityRegistered(CarePlan $carePlan, CarePlanActivity $activity): void
    {
        $personUuid = $carePlan->person?->uuid;
        if (!$personUuid || !$carePlan->uuid || !$activity->uuid) {
            throw new RuntimeException(__('care-plan.activity_ehealth_missing_identifiers'));
        }

        try {
            $response = EHealth::carePlanActivity()->getDetails($personUuid, $carePlan->uuid, $activity->uuid);
            if (!$response->successful()) {
                throw new EHealthResponseException($response);
            }
        } catch (EHealthResponseException $exception) {
            if ($exception->response->status() !== 404) {
                throw $exception;
            }

            throw new RuntimeException(__('care-plan.activity_not_in_ehealth'), previous: $exception);
        }
    }
}
