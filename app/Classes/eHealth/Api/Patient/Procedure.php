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

class Procedure extends PatientApiBase
{
    /**
     * Create the procedure for patient.
     *
     * @param  string  $uuid  Person UUID
     * @param  array  $data
     * @return EHealthResponse|PromiseInterface
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     *
     * @see https://medicaleventsmisapi.docs.apiary.io/#reference/medical-events/procedures/create-procedure
     */
    public function create(string $uuid, array $data = []): PromiseInterface|EHealthResponse
    {
        $this->setValidator($this->validateSubmittedJob(...));

        return $this->post(self::URL . "/$uuid/procedures", $data);
    }

    /**
     * Cancel procedure by marking it as entered in error.
     *
     * @param  string  $patientId  Person UUID
     * @param  string  $procedureId  Procedure UUID
     * @param  array  $data
     * @return EHealthResponse|PromiseInterface
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     *
     * @see https://medicaleventsmisapi.docs.apiary.io/#reference/medical-events/procedures/cancel-procedure
     */
    public function cancel(string $patientId, string $procedureId, array $data = []): PromiseInterface|EHealthResponse
    {
        $this->setValidator($this->validateSubmittedJob(...));

        return $this->patch(self::URL . "/$patientId/procedures/$procedureId/actions/cancel", $data);
    }

    /**
     * Return a procedure record by ID.
     *
     * @param  string  $patientId
     * @param  string  $procedureId
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     *
     * @see https://medicaleventsmisapi.docs.apiary.io/#reference/medical-events/procedures/get-procedures-by-id
     */
    public function getById(string $patientId, string $procedureId): PromiseInterface|EHealthResponse
    {
        $this->setReadContract('GET /api/patients/{patient_id}/procedures/{id}');

        return $this->get(self::URL . "/$patientId/procedures/$procedureId");
    }

    /**
     * Get a list of procedures by search params.
     *
     * @param  string  $patientId
     * @param  array{
     *     episode_id?: string,
     *     status?: string,
     *     used_reference_id?: string,
     *     based_on?: string,
     *     code?: string,
     *     managing_organization_id?: string,
     *     encounter_id?: string,
     *     origin_episode_id?: string,
     *     device_id?: string,
     *     page?: int,
     *     page_size?: int
     * }  $query
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     *
     * @see https://medicaleventsmisapi.docs.apiary.io/#reference/medical-events/procedures/get-procedures-by-search-params
     */
    public function getBySearchParams(string $patientId, array $query = []): PromiseInterface|EHealthResponse
    {
        $this->setReadContract('GET /api/patients/{patient_id}/procedures');

        $this->setDefaultPageSize();

        $mergedQuery = array_merge($this->options['query'], $query ?? []);

        return $this->get(self::URL . "/$patientId/procedures", $mergedQuery);
    }

    /**
     * Get a list of summary info about procedures.
     *
     * @param  string  $patientId
     * @param  array{code?: string, page?: int, page_size?: int}  $query
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     *
     * @see https://medicaleventsmisapi.docs.apiary.io/#reference/medical-events/patient-summary/get-procedures
     */
    public function getSummary(string $patientId, array $query = []): PromiseInterface|EHealthResponse
    {
        $this->setReadContract('GET /api/patients/{patient_id}/summary/procedures');

        $this->setDefaultPageSize();

        $mergedQuery = array_merge($this->options['query'], $query ?? []);

        return $this->get(self::URL . "/$patientId/summary/procedures", $mergedQuery);
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
