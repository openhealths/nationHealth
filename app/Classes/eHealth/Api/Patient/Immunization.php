<?php

declare(strict_types=1);

namespace App\Classes\eHealth\Api\Patient;

use App\Classes\eHealth\EHealthResponse;
use App\Exceptions\EHealth\EHealthConnectionException;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Exceptions\EHealth\EHealthValidationException;
use GuzzleHttp\Promise\PromiseInterface;

class Immunization extends PatientApiBase
{
    /**
     * Get a list of summary info about immunizations.
     *
     * @param  string  $patientId
     * @param array{
     *     vaccine_code?: string,
     *     date_from?: string,
     *     date_to?: string,
     *     page?: int,
     *     page_size?: int
     * } $query
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     *
     * @see https://medicaleventsmisapi.docs.apiary.io/#reference/medical-events/patient-summary/get-immunizations
     */
    public function getSummary(string $patientId, array $query = []): PromiseInterface|EHealthResponse
    {
        $this->setReadContract('GET /api/patients/{patient_id}/summary/immunizations');

        $this->setDefaultPageSize();

        $mergedQuery = array_merge($this->options['query'], $query);

        return $this->get(self::URL . "/$patientId/summary/immunizations", $mergedQuery);
    }

    /**
     * Get immunizations by search params.
     *
     * @param  string  $patientId
     * @param array{
     *     vaccine_code?: string,
     *     encounter_id?: string,
     *     episode_id: string,
     *     date_from?: string,
     *     date_to?: string,
     *     page?: int,
     *     page_size?: int
     * } $query
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     *
     * @see https://medicaleventsmisapi.docs.apiary.io/#reference/medical-events/immunization/get-immunizations-by-search-params
     */
    public function getBySearchParams(string $patientId, array $query = []): PromiseInterface|EHealthResponse
    {
        $this->setReadContract('GET /api/patients/{patient_id}/immunizations');

        $this->setDefaultPageSize();

        $mergedQuery = array_merge(
            $this->options['query'],
            $this->format($query, ['date_from', 'date_to'])
        );

        return $this->get(self::URL . "/$patientId/immunizations", $mergedQuery);
    }

    /**
     * Get immunization by ID. The validator returns it as a list of one, the shape sync() takes.
     *
     * @param  string  $patientId
     * @param  string  $immunizationId
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     *
     * @see https://medicaleventsmisapi.docs.apiary.io/#reference/medical-events/immunization/get-immunization-by-id
     */
    public function getById(string $patientId, string $immunizationId): PromiseInterface|EHealthResponse
    {
        $this->setReadContract('GET /api/patients/{patient_id}/immunizations/{immunization_id}');

        return $this->get(self::URL . "/$patientId/immunizations/$immunizationId");
    }

}
