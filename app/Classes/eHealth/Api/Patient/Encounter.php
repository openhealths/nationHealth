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
use Illuminate\Support\Facades\Validator;

class Encounter extends PatientApiBase
{
    /**
     * Submit signed data for creating encounter package.
     *
     * @param  string  $id  Person ID
     * @param  array{visit: array{id: string, period: array{start: string, end: string}}, signed_data: string}  $data
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     *
     * @see https://medicaleventsmisapi.docs.apiary.io/#reference/medical-events/encounter-data-package/submit-encounter-package
     */
    public function submit(string $id, array $data): PromiseInterface|EHealthResponse
    {
        $this->setValidator($this->validateSubmittedJob(...));

        return $this->post(self::URL . "/$id/encounter_package", $data);
    }

    /**
     * Submit signed data for marking the encounter and every record of its package as entered in error.
     *
     * @param  string  $id  Person ID
     * @param  array{signed_data: string, signed_data_encoding: string}  $data
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     *
     * @see https://medicaleventsmisapi.docs.apiary.io/#reference/medical-events/encounter-data-package/cancel-encounter-package
     */
    public function cancel(string $id, array $data): PromiseInterface|EHealthResponse
    {
        $this->setValidator($this->validateCancellationReceipt(...));

        return $this->patch(self::URL . "/$id/encounter_package", $data);
    }

    /** The official 202 receipt requires status; an optional link still requires both Link fields. */
    protected function validateCancellationReceipt(EHealthResponse $response): array
    {
        $data = $response->getData();
        Validator::make($data, [
            'status' => ['required', 'string'],
            'eta' => ['sometimes', 'string'],
            'links' => ['sometimes', 'array'],
            'links.*' => ['required', 'array'],
            'links.*.entity' => ['required', 'string'],
            'links.*.href' => ['required', 'string'],
        ])->validate();

        return $data;
    }

    /**
     * Get a list of short Encounter info filtered by search params.
     *
     * @param  string  $patientId
     * @param  array{
     *     period_start_from?: string,
     *     period_start_to?: string,
     *     period_end_from?: string,
     *     period_end_to?: string,
     *     episode_id?: string,
     *     status?: string,
     *     type?: string,
     *     class?: string,
     *     performer_speciality?: string,
     *     page?: int,
     *     page_size?: int
     *     }  $query
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     *
     * @see https://medicaleventsmisapi.docs.apiary.io/#reference/medical-events/patient-summary/get-short-encounters-by-search-params
     */
    public function getShortBySearchParams(string $patientId, array $query = []): PromiseInterface|EHealthResponse
    {
        $this->setReadContract('GET /api/patients/{patient_id}/summary/encounters');

        $this->setDefaultPageSize();

        $mergedQuery = array_merge($this->options['query'], $query ?? []);

        return $this->get(self::URL . "/$patientId/summary/encounters", $mergedQuery);
    }

    /**
     * Get data about Encounter by ID.
     *
     * @param  string  $patientId
     * @param  string  $encounterId
     * @param  array  $query
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     *
     * @see https://medicaleventsmisapi.docs.apiary.io/#reference/medical-events/immunization/get-encounter-by-id
     */
    public function getById(string $patientId, string $encounterId, array $query = []): PromiseInterface|EHealthResponse
    {
        $this->setReadContract('GET /api/patients/{patient_id}/encounters/{encounter_id}');

        return $this->get(self::URL . "/$patientId/encounters/$encounterId", $query);
    }

    /**
     * Get a list of encounters by search params.
     *
     * @param  string  $patientId
     * @param  array{
     *     period_start_from?: string,
     *     period_start_to?: string,
     *     period_end_from?: string,
     *     period_end_to?: string,
     *     episode_id?: string,
     *     incoming_referral_id?: string,
     *     origin_episode_id?: string,
     *     managing_organization_id?: string,
     *     page?: int,
     *     page_size?: int
     * }  $query
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     *
     * @see https://medicaleventsmisapi.docs.apiary.io/#reference/medical-events/encounter/get-encounters-by-search-params
     */
    public function getBySearchParams(string $patientId, array $query = []): PromiseInterface|EHealthResponse
    {
        $this->setReadContract('GET /api/patients/{patient_id}/encounters');

        $this->setDefaultPageSize();

        $mergedQuery = array_merge($this->options['query'], $query ?? []);

        return $this->get(self::URL . "/$patientId/encounters", $mergedQuery);
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
