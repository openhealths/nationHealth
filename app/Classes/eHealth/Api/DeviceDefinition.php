<?php

declare(strict_types=1);

namespace App\Classes\eHealth\Api;

use App\Classes\eHealth\EHealthRequest as Request;
use App\Classes\eHealth\EHealthResponse;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Exceptions\EHealth\EHealthValidationException;
use GuzzleHttp\Promise\PromiseInterface;
use App\Exceptions\EHealth\EHealthConnectionException;

use App\Dto\DeviceDefinition\Program;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Symfony\Component\ObjectMapper\ObjectMapperInterface;
use Throwable;

class DeviceDefinition extends Request
{
    protected const string URL = '/api/v2/device_definitions';

    public function lookupDeviceInProgramCatalog(string $programId, string $deviceDefinitionId): ?string
    {
        try {
            $page = 1;
            do {
                $response = $this->getMany([
                    'medical_program_id' => $programId,
                    'page_size' => 300,
                    'page' => $page,
                ]);
                $devices = $response->getData();

                if (!is_array($devices)) {
                    return null;
                }

                foreach ($devices as $device) {
                    if (!is_array($device)) {
                        continue;
                    }

                    $id = (string) ($device['id'] ?? $device['uuid'] ?? '');
                    if ($id !== $deviceDefinitionId) {
                        continue;
                    }

                    $isActive = $device['is_active'] ?? $device['isActive'] ?? true;
                    if (!filter_var($isActive, FILTER_VALIDATE_BOOLEAN)) {
                        return 'inactive';
                    }

                    if (!$this->deviceAllowsCarePlanActivity($device, $programId)) {
                        return 'inactive';
                    }

                    return 'active';
                }

                $paging = $response->getPaging();
                if (!isset($paging['page_number'], $paging['total_pages']) || (int) $paging['page_number'] !== $page) {
                    return null;
                }
                $page++;
            } while ($page <= (int) ($paging['total_pages'] ?? 1));

            return 'missing';
        } catch (Throwable $exception) {
            Log::warning('DeviceDefinition: device catalog lookup failed', [
                'program_id' => $programId,
                'device_id' => $deviceDefinitionId,
                'message' => $exception->getMessage(),
            ]);
        }

        return null;
    }

    private function mapProgramDevice(array $device, ?string $programId): Program
    {
        return app(ObjectMapperInterface::class)->map(new Collection($device), new Program($programId, now()));
    }

    public function deviceAllowsCarePlanActivity(array $device, ?string $programId = null): bool
    {
        return $this->mapProgramDevice($device, $programId)->allowsCarePlanActivity();
    }

    public function resolveProgramDevice(array $device, ?string $programId = null): ?array
    {
        return $this->mapProgramDevice($device, $programId)->resolveProgramDevice();
    }

    public function isDeviceInProgramCatalog(string $programId, string $deviceDefinitionId): bool
    {
        return $this->lookupDeviceInProgramCatalog($programId, $deviceDefinitionId) === 'active';
    }

    /**
     * Search all active device definitions in the system.
     *
     * @param  array{
     *     classification_type_system?: string,  // The system of Classification type that corresponds to dictionary name
     *     classification_type_code?: string, // The code of Classification type that corresponds to dictionary value
     *     model_number?: string,  // Model number for the device
     *     name?: string,  // Device name
     *     name_type?: string,  // Device name type. Dictionary device_name_type
     *     medical_program_id?: string,
     *     is_active?: bool,
     *     page?: int,
     *     page_size?: int
     * }  $filters
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     *
     * @see https://ehealthmisapi1.docs.apiary.io/#reference/public.-devices/get-device-definitions-v2/get-device-definitions-v2
     */
    public function getMany(array $filters = []): PromiseInterface|EHealthResponse
    {
        $this->setDefaultPageSize();

        $mergedQuery = array_merge(
            $this->options['query'] ?? [],
            $filters
        );

        return $this->get(self::URL, $mergedQuery);
    }

    /**
     * Get a single device definition by its UUID.
     *
     * @see https://ehealthmisapi1.docs.apiary.io/#reference/public.-devices/get-device-definitions-v2/get-device-definitions-v2
     */
    public function getById(string $id): PromiseInterface|EHealthResponse
    {
        return $this->get(self::URL . '/' . $id);
    }
}
