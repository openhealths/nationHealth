<?php

declare(strict_types=1);

namespace App\Classes\eHealth\Api\Patient;

use App\Classes\eHealth\EHealthResponse;
use App\Classes\eHealth\Errors\MedicalEventsErrorTranslator;
use App\Contracts\ApiErrorTranslator;
use App\Exceptions\EHealth\EHealthConnectionException;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Exceptions\EHealth\EHealthValidationException;
use GuzzleHttp\Promise\PromiseInterface;

class Device extends PatientApiBase
{
    /**
     * Get a list of summary info about devices.
     *
     * @param  string  $patientId
     * @param array{
     *     type?: string,
     *     asserted_date_from?: string,
     *     asserted_date_to?: string,
     *     page?: int,
     *     page_size?: int
     * } $query
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     *
     * @see https://medicaleventsmisapi.docs.apiary.io/#reference/medical-events/patient-summary/get-devices-by-search-params-(summary)
     */
    public function getSummary(string $patientId, array $query = []): PromiseInterface|EHealthResponse
    {
        $this->setReadContract('GET /api/patients/{patient_id}/summary/devices');

        $this->setDefaultPageSize();

        $mergedQuery = array_merge($this->options['query'], $query);

        return $this->get(self::URL . "/$patientId/summary/devices", $mergedQuery);
    }

    /**
     * Get devices by search params.
     *
     * @param  string  $patientId
     * @param array{
     *     type?: string,
     *     encounter_id?: string,
     *     episode_id?: string,
     *     definition?: string,
     *     model_number?: string,
     *     manufacturer?: string,
     *     name?: string,
     *     recorder?: string,
     *     recorder_legal_entity_id?: string,
     *     status?: string,
     *     serial_number?: string,
     *     inserted_at_from?: string,
     *     inserted_at_to?: string,
     *     page?: int,
     *     page_size?: int
     * } $query
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     *
     * @see https://medicaleventsmisapi.docs.apiary.io/#reference/medical-events/device/get-devices-by-search-params
     */
    public function getBySearchParams(string $patientId, array $query = []): PromiseInterface|EHealthResponse
    {
        $this->setReadContract('GET /api/patients/{patient_id}/devices');

        $this->setDefaultPageSize();

        $mergedQuery = array_merge(
            $this->options['query'],
            $this->format($query, ['inserted_at_from', 'inserted_at_to'])
        );

        return $this->get(self::URL . "/$patientId/devices", $mergedQuery);
    }

    /**
     * Return detail data by ID.
     *
     * @param  string  $patientId
     * @param  string  $deviceId
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     *
     * @see https://medicaleventsmisapi.docs.apiary.io/#reference/medical-events/device/get-device-by-id
     */
    public function getById(string $patientId, string $deviceId): PromiseInterface|EHealthResponse
    {
        $this->setReadContract('GET /api/patients/{patient_id}/devices/{device_id}');

        return $this->get(self::URL . "/$patientId/devices/$deviceId");
    }

    /**
     * Return detail data about a device of the patient summary by ID.
     *
     * @param  string  $patientId
     * @param  string  $deviceId
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     *
     * @see https://medicaleventsmisapi.docs.apiary.io/#reference/medical-events/patient-summary/get-device-by-id-(summary)
     */
    public function getSummaryById(string $patientId, string $deviceId): PromiseInterface|EHealthResponse
    {
        $this->setReadContract('GET /api/patients/{patient_id}/summary/devices/{device_id}');

        return $this->get(self::URL . "/$patientId/summary/devices/$deviceId");
    }

    /**
     * Get the translator of the errors the medical events resources return.
     *
     * @return ApiErrorTranslator
     */
    protected function errorTranslator(): ApiErrorTranslator
    {
        return new MedicalEventsErrorTranslator();
    }

}
