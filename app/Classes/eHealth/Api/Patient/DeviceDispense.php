<?php

declare(strict_types=1);

namespace App\Classes\eHealth\Api\Patient;

use App\Classes\eHealth\EHealthResponse;
use App\Exceptions\EHealth\EHealthConnectionException;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Exceptions\EHealth\EHealthValidationException;
use GuzzleHttp\Promise\PromiseInterface;

class DeviceDispense extends PatientApiBase
{
    public function getById(string $patientId, string $deviceDispenseId): PromiseInterface|EHealthResponse
    {
        $this->setReadContract('GET /api/patients/{patient_id}/device_dispenses/{device_dispense_id}');

        return $this->get(self::URL . "/$patientId/device_dispenses/$deviceDispenseId");
    }

    /**
     * Get device dispenses by search params in patient context.
     *
     * @param  string  $patientId
     * @param array{
     *     based_on?: string,
     *     location?: string,
     *     performer?: string,
     *     status?: string,
     *     when_handed_over_from?: string,
     *     when_handed_over_to?: string,
     *     part_of?: string,
     *     device?: string,
     *     performer_legal_entity?: string,
     *     inserted_at_from?: string,
     *     inserted_at_to?: string,
     *     page?: int,
     *     page_size?: int,
     *     encounter?: string,
     *     context_episode_id?: string,
     *     origin_episode_id?: string,
     *     device_code?: string,
     *     program?: string
     * } $query
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     *
     * @see https://medicaleventsmisapi.docs.apiary.io/#reference/device-dispenses/get-device-dispenses-by-search-params-in-patient-context/get-device-dispenses-by-search-params-in-patient-context
     */
    public function getBySearchParams(string $patientId, array $query = []): PromiseInterface|EHealthResponse
    {
        $this->setReadContract('GET /api/patients/{patient_id}/device_dispenses');

        $this->setDefaultPageSize();

        $mergedQuery = array_merge(
            $this->options['query'],
            $this->format($query)
        );

        return $this->get(self::URL . "/$patientId/device_dispenses", $mergedQuery);
    }

}
