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

class DeviceAssociation extends PatientApiBase
{
    /**
     * Get device associations by search params.
     *
     * @param  string  $patientId
     * @param array{
     *     device_id?: string,
     *     body_site?: string,
     *     encounter_id?: string,
     *     episode_id?: string,
     *     status?: string,
     *     recorder?: string,
     *     recorder_legal_entity_id?: string,
     *     association_date_from?: string,
     *     association_date_to?: string,
     *     recorded_from?: string,
     *     recorded_to?: string,
     *     inserted_at_from?: string,
     *     inserted_at_to?: string,
     *     page?: int,
     *     page_size?: int
     * } $query
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     *
     * @see https://medicaleventsmisapi.docs.apiary.io/#reference/medical-events/device-association/get-device-associations-by-search-params
     */
    public function getBySearchParams(string $patientId, array $query = []): PromiseInterface|EHealthResponse
    {
        $this->setReadContract('GET /api/patients/{patient_id}/device_associations');

        $this->setDefaultPageSize();

        $mergedQuery = array_merge(
            $this->options['query'],
            $this->format($query, [
                'association_date_from',
                'association_date_to',
                'recorded_from',
                'recorded_to',
                'inserted_at_from',
                'inserted_at_to'
            ])
        );

        return $this->get(self::URL . "/$patientId/device_associations", $mergedQuery);
    }

    /**
     * Return detail data by ID.
     *
     * @param  string  $patientId
     * @param  string  $deviceAssociationId
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     *
     * @see https://medicaleventsmisapi.docs.apiary.io/#reference/medical-events/device-association/get-device-association-by-id
     */
    public function getById(string $patientId, string $deviceAssociationId): PromiseInterface|EHealthResponse
    {
        $this->setReadContract('GET /api/patients/{patient_id}/device_associations/{device_association_id}');

        return $this->get(self::URL . "/$patientId/device_associations/$deviceAssociationId");
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
