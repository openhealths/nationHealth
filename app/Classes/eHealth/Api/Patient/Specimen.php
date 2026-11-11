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

class Specimen extends PatientApiBase
{
    /**
     * Get specimens of the patient by search params.
     *
     * @param  string  $patientId
     * @param array{
     *     status?: string,
     *     type?: string,
     *     registered_by?: string,
     *     collected_from?: string,
     *     collected_to?: string,
     *     container_identifier?: string,
     *     container_type?: string,
     *     parent?: string,
     *     request?: string,
     *     encounter?: string,
     *     page?: int,
     *     page_size?: int
     * } $query
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     *
     * @see https://medicaleventsmisapi.docs.apiary.io/#reference/medical-events/specimen/get-specimen-by-search-params
     */
    public function getBySearchParams(string $patientId, array $query = []): PromiseInterface|EHealthResponse
    {
        $this->setReadContract('GET /api/patients/{patient_id}/specimens');

        $this->setDefaultPageSize();

        $mergedQuery = array_merge(
            $this->options['query'],
            $this->format($query, ['collected_from', 'collected_to'])
        );

        return $this->get(self::URL . "/$patientId/specimens", $mergedQuery);
    }

    /**
     * Get details of the patient specimen by its ID.
     *
     * @param  string  $patientId
     * @param  string  $specimenId
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     *
     * @see https://medicaleventsmisapi.docs.apiary.io/#reference/medical-events/specimen/get-specimen-details
     */
    public function getDetails(string $patientId, string $specimenId): PromiseInterface|EHealthResponse
    {
        $this->setReadContract('GET /api/patients/{patient_id}/specimens/{id}');

        return $this->get(self::URL . "/$patientId/specimens/$specimenId");
    }

    /**
     * Get a specimen by its human-readable accession identifier.
     *
     * @param  string  $accessionIdentifier
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     *
     * @see https://medicaleventsmisapi.docs.apiary.io/#reference/medical-events/specimen/get-specimen-by-accession-identifier
     */
    public function getByAccessionIdentifier(string $accessionIdentifier): PromiseInterface|EHealthResponse
    {
        $this->setReadContract('GET /api/specimens/{accession_identifier}');

        return $this->get("/api/specimens/$accessionIdentifier");
    }

    /**
     * Create a specimen of the patient outside the encounter package with signed content. Processed asynchronously.
     *
     * @param  string  $patientId
     * @param  array  $payload
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     *
     * @see https://medicaleventsmisapi.docs.apiary.io/#reference/medical-events/specimen/create-specimen
     */
    public function create(string $patientId, array $payload): PromiseInterface|EHealthResponse
    {
        $this->setReadContract('POST /api/patients/{patient_id}/specimens', false);

        return $this->post(self::URL . "/$patientId/specimens", $payload);
    }

    /**
     * Set the time the patient specimen was received for processing. Processed asynchronously.
     *
     * @param  string  $patientId
     * @param  string  $specimenId
     * @param  array  $payload
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     *
     * @see https://medicaleventsmisapi.docs.apiary.io/#reference/medical-events/specimen/process-specimen
     */
    public function process(string $patientId, string $specimenId, array $payload): PromiseInterface|EHealthResponse
    {
        $this->setReadContract('PATCH /api/patients/{patient_id}/specimens/{id}/actions/process', false);

        return $this->patch(self::URL . "/$patientId/specimens/$specimenId/actions/process", $payload);
    }

    /**
     * Mark the patient specimen as rejected. Processed asynchronously.
     *
     * @param  string  $patientId
     * @param  string  $specimenId
     * @param  array  $payload
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     *
     * @see https://medicaleventsmisapi.docs.apiary.io/#reference/medical-events/specimen/reject-specimen
     */
    public function reject(string $patientId, string $specimenId, array $payload): PromiseInterface|EHealthResponse
    {
        $this->setReadContract('PATCH /api/patients/{patient_id}/specimens/{id}/actions/reject', false);

        return $this->patch(self::URL . "/$patientId/specimens/$specimenId/actions/reject", $payload);
    }

    /**
     * Mark the patient specimen as unavailable. Processed asynchronously.
     *
     * @param  string  $patientId
     * @param  string  $specimenId
     * @param  array  $payload
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     *
     * @see https://medicaleventsmisapi.docs.apiary.io/#reference/medical-events/specimen/invalidate-specimen
     */
    public function invalidate(string $patientId, string $specimenId, array $payload): PromiseInterface|EHealthResponse
    {
        $this->setReadContract('PATCH /api/patients/{patient_id}/specimens/{id}/actions/invalidate', false);

        return $this->patch(self::URL . "/$patientId/specimens/$specimenId/actions/invalidate", $payload);
    }

    /**
     * Mark the patient specimen as entered in error with a signed package. Processed asynchronously.
     *
     * @param  string  $patientId
     * @param  string  $specimenId
     * @param  array  $payload
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     *
     * @see https://medicaleventsmisapi.docs.apiary.io/#reference/medical-events/specimen/cancel-specimen
     */
    public function cancel(string $patientId, string $specimenId, array $payload): PromiseInterface|EHealthResponse
    {
        $this->setReadContract('PATCH /api/patients/{patient_id}/specimens/{id}/actions/cancel', false);

        return $this->patch(self::URL . "/$patientId/specimens/$specimenId/actions/cancel", $payload);
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
