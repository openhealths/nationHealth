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

class Observation extends PatientApiBase
{
    /**
     * Return an observation context record by IDs.
     *
     * @param  string  $patientUuid
     * @param  string  $episodeUuid
     * @param  array  $data
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     */
    public function getInEpisodeContext(
        string $patientUuid,
        string $episodeUuid,
        array $data = []
    ): PromiseInterface|EHealthResponse {
        $this->setReadContract('GET /api/patients/{patient_id}/episodes/{episode_id}/observations');

        return $this->get(self::URL . "/$patientUuid/episodes/$episodeUuid/observations", $data);
    }

    /**
     * Get observation by ID.
     *
     * @param  string  $patientId
     * @param  string  $observationId
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     *
     * @see https://medicaleventsmisapi.docs.apiary.io/#reference/medical-events/observation/get-observation-by-id
     */
    public function getById(string $patientId, string $observationId): PromiseInterface|EHealthResponse
    {
        $this->setReadContract('GET /api/patients/{patient_id}/observations/{observation_id}');

        return $this->get(self::URL . "/$patientId/observations/$observationId");
    }

    /**
     * Get a list of summary info about observations.
     *
     * @param  string  $patientId
     * @param  array{code?: string, issued_from?: string, issued_to?: string, page?: int, page_size?: int}  $query
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     *
     * @see https://medicaleventsmisapi.docs.apiary.io/#reference/medical-events/patient-summary/get-observations
     */
    public function getSummary(string $patientId, array $query = []): PromiseInterface|EHealthResponse
    {
        $this->setReadContract('GET /api/patients/{patient_id}/summary/observations');

        $this->setDefaultPageSize();

        $mergedQuery = array_merge($this->options['query'], $this->format($query, ['issued_from', 'issued_to']));

        return $this->get(self::URL . "/$patientId/summary/observations", $mergedQuery);
    }

    /**
     * Get a list of observations.
     *
     * @param  string  $patientId
     * @param  array{
     *     code?: string,
     *     encounter_id?: string,
     *     diagnostic_report_id?: string,
     *     episode_id?: string,
     *     issued_from?: string,
     *     issued_to?: string,
     *     device_id?: string,
     *     managing_organization_id?: string,
     *     specimen_id?: string,
     *     page?: int,
     *     page_size?: int
     * }  $query
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     *
     * @see https://medicaleventsmisapi.docs.apiary.io/#reference/medical-events/observation/get-observations-by-searh-params
     */
    public function getBySearchParams(string $patientId, array $query = []): PromiseInterface|EHealthResponse
    {
        $this->setReadContract('GET /api/patients/{patient_id}/observations');

        $this->setDefaultPageSize();

        $mergedQuery = array_merge($this->options['query'], $this->format($query, ['issued_from', 'issued_to']));

        return $this->get(self::URL . "/$patientId/observations", $mergedQuery);
    }

    /**
     * Get a detail info about observation summary.
     *
     * @param  string  $patientId
     * @param  string  $id  Observation ID
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     *
     * @see https://medicaleventsmisapi.docs.apiary.io/#reference/medical-events/patient-summary/get-observation-by-id
     */
    public function getSummaryById(string $patientId, string $id): PromiseInterface|EHealthResponse
    {
        $this->setReadContract('GET /api/patients/{patient_id}/summary/observations/{id}');

        return $this->get(self::URL . "/$patientId/summary/observations/$id");
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
