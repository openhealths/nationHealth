<?php

declare(strict_types=1);

namespace App\Services\MedicalEvents;

use App\Classes\eHealth\EHealth;

/**
 * eHealth writes for the care-plan aggregate.
 *
 * Livewire still builds KEP payloads and persists local rows. This service is
 * the only place those signed bodies are posted, and every write is polled
 * through {@see EHealthJobResolver}.
 */
class CarePlanLifecycleService
{
    public function __construct(
        private readonly EHealthJobResolver $jobResolver,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function submitSignedCreate(string $patientUuid, string $signedContent): array
    {
        $response = EHealth::carePlan()->create($patientUuid, [
            'signed_data' => $signedContent,
            'signed_data_encoding' => 'base64',
        ]);

        return $this->jobResolver->resolve($response->getData());
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function cancel(string $patientUuid, string $carePlanUuid, array $payload): array
    {
        $response = EHealth::carePlan()->cancel($patientUuid, $carePlanUuid, $payload);

        return $this->jobResolver->resolve($response->getData());
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function complete(string $patientUuid, string $carePlanUuid, array $payload): array
    {
        $response = EHealth::carePlan()->complete($patientUuid, $carePlanUuid, $payload);

        return $this->jobResolver->resolve($response->getData());
    }

    /**
     * @return array<string, mixed>
     */
    public function getDetails(string $patientUuid, string $carePlanUuid): array
    {
        return EHealth::carePlan()->getDetails($patientUuid, $carePlanUuid)->getData();
    }
}
