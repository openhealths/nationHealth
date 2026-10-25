<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\MedicalEvents\MedicationRequest;

use App\Classes\eHealth\EHealth;
use App\Repositories\MedicalEvents\MedicationRequestRepository;
use Illuminate\Support\Facades\Log;
use Throwable;

trait ResolvesActiveMedicationRequest
{
    protected function resolveActiveMedicationRequestId(string $personUuid, string $localId): string
    {
        $repository = app(MedicationRequestRepository::class);
        $requestRecord = $repository->findByUuid($localId);

        if ($requestRecord && !empty($requestRecord->ehealthPayload['active_id'])) {
            return (string) $requestRecord->ehealthPayload['active_id'];
        }

        if (empty($personUuid) && $requestRecord) {
            $personUuid = (string) ($requestRecord->personId ? $repository->personUuid($requestRecord->personId) : '');
        }

        if (empty($personUuid)) {
            return $localId;
        }

        try {
            $queries = [];
            if ($requestRecord && !empty($requestRecord->requestNumber)) {
                $queries[] = ['request_number' => $requestRecord->requestNumber];
            }
            $queries[] = [];

            foreach ($queries as $query) {
                $activeResponse = EHealth::medicationRequest()->getBySearchParams($personUuid, $query)->getData();
                $activeItems = isset($activeResponse['data']) && is_array($activeResponse['data'])
                    ? $activeResponse['data']
                    : (is_array($activeResponse) ? $activeResponse : []);

                if (is_array($activeItems)) {
                    foreach ($activeItems as $item) {
                        if (empty($item['id'])) {
                            continue;
                        }
                        $isMatch = $item['id'] === $localId
                            || ($requestRecord && !empty($requestRecord->requestNumber) && ($item['request_number'] ?? '') === $requestRecord->requestNumber);

                        if ($isMatch) {
                            if ($requestRecord && $item['id'] !== $localId) {
                                $repository->rememberActiveId($requestRecord, (string) $item['id']);
                            }

                            return (string) $item['id'];
                        }
                    }
                }
            }
        } catch (Throwable $e) {
            Log::warning('Failed to resolve active eHealth ID for prescription: ' . $e->getMessage());
        }

        return $localId;
    }
}
