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

class Condition extends PatientApiBase
{
    /**
     * Get a list of summary info.
     *
     * @param  string  $patientId
     * @param  array{code?: string, onset_date_from?: string, onset_date_to?: string, page?: int, page_size?: int}  $query
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     *
     * @see https://medicaleventsmisapi.docs.apiary.io/#reference/medical-events/patient-summary/get-conditions
     */
    public function getSummary(string $patientId, array $query = []): PromiseInterface|EHealthResponse
    {
        $this->setReadContract('GET /api/patients/{patient_id}/summary/conditions');

        $this->setDefaultPageSize();

        $mergedQuery = array_merge($this->options['query'], $query ?? []);

        return $this->get(self::URL . "/$patientId/summary/conditions", $mergedQuery);
    }

    /**
     * Return a condition context record by IDs.
     *
     * @param  string  $patientId
     * @param  string  $episodeId
     * @param  array  $data
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     *
     * @see https://medicaleventsmisapi.docs.apiary.io/#reference/medical-events/conditions/get-condition-context
     */
    public function getInEpisodeContext(
        string $patientId,
        string $episodeId,
        array $data = []
    ): PromiseInterface|EHealthResponse {
        $this->setReadContract('GET /api/patients/{patient_id}/episodes/{episode_id}/conditions');

        return $this->get(self::URL . "/$patientId/episodes/$episodeId/conditions", $data);
    }

    /**
     * Return detail data by ID.
     *
     * @param  string  $patientId
     * @param  string  $conditionId
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     *
     * @see https://medicaleventsmisapi.docs.apiary.io/#reference/medical-events/conditions/get-condition-by-id
     */
    public function getById(string $patientId, string $conditionId): PromiseInterface|EHealthResponse
    {
        $this->setReadContract('GET /api/patients/{patient_id}/conditions/{condition_id}');

        return $this->get(self::URL . "/$patientId/conditions/$conditionId");
    }

    /**
     * Get a list of observations.
     *
     * @param  string  $patientId
     * @param  array{
     *     code?: string,
     *     encounter_id?: string,
     *     episode_id?: string,
     *     onset_date_from?: string,
     *     onset_date_to?: string,
     *     managing_organization_id?: string,
     *     page?: int,
     *     page_size?: int
     * }  $query
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     *
     * @see https://medicaleventsmisapi.docs.apiary.io/#reference/medical-events/conditions/get-conditions-by-search-params
     */
    public function getBySearchParams(string $patientId, array $query = []): PromiseInterface|EHealthResponse
    {
        $this->setReadContract('GET /api/patients/{patient_id}/conditions');

        $this->setDefaultPageSize();

        $mergedQuery = array_merge($this->options['query'], $this->format($query, ['onset_date_from', 'onset_date_to']));

        return $this->get(self::URL . "/$patientId/conditions", $mergedQuery);
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
