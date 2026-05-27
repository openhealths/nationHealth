<?php

declare(strict_types=1);

namespace App\Classes\eHealth\Api;

use App\Classes\eHealth\EHealthRequest as Request;
use App\Classes\eHealth\EHealthResponse;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Exceptions\EHealth\EHealthValidationException;
use GuzzleHttp\Promise\PromiseInterface;
use App\Exceptions\EHealth\EHealthConnectionException;

class DeviceDefinition extends Request
{
    protected const string URL = '/api/v2/device_definitions';

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
}
