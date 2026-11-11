<?php

declare(strict_types=1);

namespace App\Classes\eHealth\Api\Patient;

use App\Classes\eHealth\EHealthResponse;
use App\Exceptions\EHealth\EHealthConnectionException;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Exceptions\EHealth\EHealthValidationException;
use GuzzleHttp\Promise\PromiseInterface;

class ClinicalImpression extends PatientApiBase
{
    /**
     * Get clinical impression by ID.
     *
     * @param  string  $patientId
     * @param  string  $clinicalImpressionId
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     *
     * @see https://medicaleventsmisapi.docs.apiary.io/#reference/medical-events/clinical-impression/get-clinical-impression-by-id
     */
    public function getById(string $patientId, string $clinicalImpressionId): PromiseInterface|EHealthResponse
    {
        $this->setReadContract('GET /api/patients/{patient_id}/clinical_impressions/{clinical_impression_id}');

        return $this->get(self::URL . "/$patientId/clinical_impressions/$clinicalImpressionId");
    }

    /**
     * Get a list of summary info about clinical impressions.
     *
     * @param  string  $patientId
     * @param  array{encounter_id?: string, episode_id?: string, code?: string, status?: string, page?: int, page_size?: int}  $query
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     *
     * @see https://medicaleventsmisapi.docs.apiary.io/#reference/medical-events/patient-summary/get-clinical-impressions
     */
    public function getSummary(string $patientId, array $query = []): PromiseInterface|EHealthResponse
    {
        $this->setReadContract('GET /api/patients/{patient_id}/summary/clinical_impressions');

        $this->setDefaultPageSize();

        $mergedQuery = array_merge($this->options['query'], $query ?? []);

        return $this->get(self::URL . "/$patientId/summary/clinical_impressions", $mergedQuery);
    }

    /**
     * Get a list of clinical impressions.
     *
     * @param  string  $patientId
     * @param  array{
     *     code?: string,
     *     status?: string,
     *     encounter_id?: string,
     *     episode_id?: string,
     *     effective_date_from?: string,
     *     effective_date_to?: string,
     *     page?: int,
     *     page_size?: int
     * }  $query
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     *
     * @see https://medicaleventsmisapi.docs.apiary.io/#reference/medical-events/clinical-impression/get-clinical-impression-by-search-params
     */
    public function getBySearchParams(string $patientId, array $query = []): PromiseInterface|EHealthResponse
    {
        $this->setReadContract('GET /api/patients/{patient_id}/clinical_impressions');

        $this->setDefaultPageSize();

        $mergedQuery = array_merge($this->options['query'], $this->format($query, ['effective_date_from', 'effective_date_to']));

        return $this->get(self::URL . "/$patientId/clinical_impressions", $mergedQuery);
    }

}
