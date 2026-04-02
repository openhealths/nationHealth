<?php

declare(strict_types=1);

namespace App\Classes\eHealth\Api\Patient;

use App\Classes\eHealth\EHealthResponse;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Exceptions\EHealth\EHealthValidationException;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\ConnectionException;

class Procedure extends PatientApiBase
{
    /**
     * Create the procedure for patient.
     *
     * @param  string  $uuid  Person UUID
     * @param  array  $data
     * @return EHealthResponse|PromiseInterface
     * @throws ConnectionException|EHealthValidationException|EHealthResponseException
     *
     * @see https://medicaleventsmisapi.docs.apiary.io/#reference/medical-events/procedures/create-procedure
     */
    public function create(string $uuid, array $data = []): PromiseInterface|EHealthResponse
    {
        return $this->post(self::URL . "/$uuid/procedures", $data);
    }

    /**
     * Return a procedure record by ID.
     *
     * @param  string  $patientId
     * @param  string  $procedureId
     * @param  array  $data
     * @return PromiseInterface|EHealthResponse
     * @throws ConnectionException|EHealthValidationException|EHealthResponseException
     *
     * @see https://medicaleventsmisapi.docs.apiary.io/#reference/medical-events/procedures/get-procedures-by-id
     */
    public function getById(string $patientId, string $procedureId, array $data = []): PromiseInterface|EHealthResponse
    {
        return $this->get(self::URL . "/$patientId/procedures/$procedureId", $data);
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
     * @throws ConnectionException|EHealthValidationException|EHealthResponseException
     *
     * @see https://medicaleventsmisapi.docs.apiary.io/#reference/medical-events/procedures/get-procedures-by-search-params
     */
    public function getBySearchParams(string $patientId, array $query = []): PromiseInterface|EHealthResponse
    {
        $this->setDefaultPageSize();

        $mergedQuery = array_merge($this->options['query'], $query ?? []);

        return $this->get(self::URL . "/$patientId/procedures", $mergedQuery);
    }
}
