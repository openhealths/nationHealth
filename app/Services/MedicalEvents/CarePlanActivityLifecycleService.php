<?php

declare(strict_types=1);

namespace App\Services\MedicalEvents;

use App\Classes\eHealth\EHealth;

/**
 * eHealth writes for care-plan activities.
 */
class CarePlanActivityLifecycleService
{
    public function __construct(
        private readonly EHealthJobResolver $jobResolver,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function submitSignedCreate(string $patientUuid, string $carePlanUuid, string $signedContent): array
    {
        $response = EHealth::carePlanActivity()->create($patientUuid, $carePlanUuid, [
            'signed_data' => $signedContent,
            'signed_data_encoding' => 'base64',
        ]);

        return $this->jobResolver->resolve($response->getData());
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function cancel(string $patientUuid, string $carePlanUuid, string $activityUuid, array $payload): array
    {
        $response = EHealth::carePlanActivity()->cancel($patientUuid, $carePlanUuid, $activityUuid, $payload);

        return $this->jobResolver->resolve($response->getData());
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function complete(string $patientUuid, string $carePlanUuid, string $activityUuid, array $payload): array
    {
        $response = EHealth::carePlanActivity()->complete($patientUuid, $carePlanUuid, $activityUuid, $payload);

        return $this->jobResolver->resolve($response->getData());
    }

    /**
     * @return array<string, mixed>
     */
    public function getDetails(string $patientUuid, string $carePlanUuid, string $activityUuid): array
    {
        return EHealth::carePlanActivity()->getDetails($patientUuid, $carePlanUuid, $activityUuid)->getData();
    }
}
