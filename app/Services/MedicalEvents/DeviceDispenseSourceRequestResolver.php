<?php

declare(strict_types=1);

namespace App\Services\MedicalEvents;

use App\Classes\eHealth\EHealth;
use App\Enums\Person\DeviceRequestStatus;
use App\Exceptions\EHealth\EHealthConnectionException;
use App\Exceptions\EHealth\EHealthException;

/**
 * Reads the Device Requests a patient may be dispensed against, together with how much of each has
 * already been handed over.
 *
 * The leftover is what tells a partial dispense apart from a full one (TV 3.22.1.1), so it is resolved
 * from the dispenses eHealth already holds rather than from the package being written.
 */
class DeviceDispenseSourceRequestResolver
{
    /**
     * @var array<string, DeviceDispenseSourceRequest|null>
     */
    private array $resolved = [];

    /**
     * Active device requests of the patient, in the flat shape the dispense rules read.
     *
     * @param  string  $patientUuid
     * @return array<int, DeviceDispenseSourceRequest>
     */
    public function activeRequests(string $patientUuid): array
    {
        $requests = $this->fetchRequests($patientUuid);

        return collect($requests)
            ->map(fn (array $request): DeviceDispenseSourceRequest => DeviceDispenseSourceRequest::fromArray(
                $request,
                $this->dispensedQuantity($patientUuid, (string) (data_get($request, 'id') ?? data_get($request, 'uuid') ?? ''))
            ))
            ->filter(static fn (DeviceDispenseSourceRequest $request): bool => $request->uuid !== '')
            ->values()
            ->all();
    }

    /**
     * One device request of the patient, or null when it is not among the ones available for dispensing.
     *
     * @param  string  $patientUuid
     * @param  string  $requestUuid
     * @return DeviceDispenseSourceRequest|null
     */
    public function resolve(string $patientUuid, string $requestUuid): ?DeviceDispenseSourceRequest
    {
        $cacheKey = $patientUuid . ':' . $requestUuid;

        if (array_key_exists($cacheKey, $this->resolved)) {
            return $this->resolved[$cacheKey];
        }

        $match = collect($this->activeRequests($patientUuid))
            ->first(static fn (DeviceDispenseSourceRequest $request): bool => $request->uuid === $requestUuid);

        return $this->resolved[$cacheKey] = $match;
    }

    /**
     * How much has already been handed over against the given device request.
     *
     * @param  string  $patientUuid
     * @param  string  $requestUuid
     * @return int
     */
    public function dispensedQuantity(string $patientUuid, string $requestUuid): int
    {
        if ($requestUuid === '') {
            return 0;
        }

        try {
            $dispenses = EHealth::deviceDispense()
                ->getBySearchParams($patientUuid, ['based_on' => $requestUuid])
                ->validate();
        } catch (EHealthException|EHealthConnectionException $exception) {
            $exception->handle('Error while loading device dispenses of a device request');

            return 0;
        }

        return collect($dispenses)
            ->reject(
                static fn (array $dispense): bool => in_array(
                    (string) data_get($dispense, 'status', ''),
                    ['entered_in_error', 'rejected'],
                    true
                )
            )
            ->sum(static fn (array $dispense): int => (int) data_get($dispense, 'details.quantity', 0));
    }

    /**
     * @param  string  $patientUuid
     * @return array<int, array<string, mixed>>
     */
    private function fetchRequests(string $patientUuid): array
    {
        try {
            return collect(
                EHealth::deviceRequest()
                    ->getBySearchParams($patientUuid, [
                        'status' => DeviceRequestStatus::ACTIVE->value,
                        'requester_legal_entity' => legalEntity()->uuid
                    ])
                    ->getData()
            )->values()->all();
        } catch (EHealthException|EHealthConnectionException $exception) {
            $exception->handle('Error while loading device requests available for dispensing');

            return [];
        }
    }
}
