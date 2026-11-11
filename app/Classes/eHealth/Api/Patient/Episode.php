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

class Episode extends PatientApiBase
{
    /**
     * Create episode.
     *
     * @param  string  $id
     * @param  array  $data
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     *
     * @see https://medicaleventsmisapi.docs.apiary.io/#reference/medical-events/episode-of-care/create-episode
     */
    public function create(string $id, array $data): PromiseInterface|EHealthResponse
    {
        $this->setReadContract('POST /api/patients/{patient_id}/episodes', false);

        return $this->post(self::URL . "/$id/episodes", $data);
    }

    /**
     * Update the editable parameters of an episode.
     *
     * @param  string  $patientId
     * @param  string  $episodeId
     * @param  array  $data
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     *
     * @see https://medicaleventsmisapi.docs.apiary.io/#reference/medical-events/episode-of-care/update-episode
     */
    public function update(string $patientId, string $episodeId, array $data): PromiseInterface|EHealthResponse
    {
        $this->setReadContract('PATCH /api/patients/{patient_id}/episodes/{episode_id}', false);

        return $this->patch(self::URL . "/$patientId/episodes/$episodeId", $data);
    }

    /**
     * Mark the episode as entered in error.
     *
     * @param  string  $patientId
     * @param  string  $episodeId
     * @param  array  $data
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     *
     * @see https://medicaleventsmisapi.docs.apiary.io/#reference/medical-events/episode-of-care/cancel-episode
     */
    public function cancel(string $patientId, string $episodeId, array $data): PromiseInterface|EHealthResponse
    {
        $this->setReadContract('PATCH /api/patients/{patient_id}/episodes/{episode_id}/actions/cancel', false);

        return $this->patch(self::URL . "/$patientId/episodes/$episodeId/actions/cancel", $data);
    }

    /**
     * Close the episode with the reason and the summary behind it.
     *
     * @param  string  $patientId
     * @param  string  $episodeId
     * @param  array  $data
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     *
     * @see https://medicaleventsmisapi.docs.apiary.io/#reference/medical-events/episode-of-care/close-episode
     */
    public function close(string $patientId, string $episodeId, array $data): PromiseInterface|EHealthResponse
    {
        $this->setReadContract('PATCH /api/patients/{patient_id}/episodes/{episode_id}/actions/close', false);

        return $this->patch(self::URL . "/$patientId/episodes/$episodeId/actions/close", $data);
    }

    /**
     * Get episode by ID.
     *
     * @param  string  $patientId
     * @param  string  $episodeId
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     *
     * @see https://medicaleventsmisapi.docs.apiary.io/#reference/medical-events/episode-of-care/get-episode-by-id
     */
    public function getById(string $patientId, string $episodeId): PromiseInterface|EHealthResponse
    {
        $this->setReadContract('GET /api/patients/{patient_id}/episodes/{episode_id}', true);

        return $this->get(self::URL . "/$patientId/episodes/$episodeId");
    }

    /**
     * Get episodes by search params.
     * Use period_from period_to to find episodes that were active in a certain period of time
     *
     * @param  string  $patientId  Person ID
     * @param  array{
     *     period_from?: string,
     *     period_to?: string,
     *     code?: string,
     *     status?: string,
     *     managing_organization_id?: string,
     *     page?: int,
     *     page_size?: int
     * }  $query
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     *
     * @see https://medicaleventsmisapi.docs.apiary.io/#reference/medical-events/episode-of-care/get-episodes-by-search-params
     */
    public function getBySearchParams(string $patientId, array $query = []): PromiseInterface|EHealthResponse
    {
        $this->setReadContract('GET /api/patients/{patient_id}/episodes', true);

        $this->setDefaultPageSize();

        $mergedQuery = array_merge($this->options['query'], $query ?? []);

        return $this->get(self::URL . "/$patientId/episodes", $mergedQuery);
    }

    /**
     * Get brief information about episodes, in order not to disclose confidential and sensitive data.
     *
     * @param  string  $patientId
     * @param  array{
     *     period_start_from?: string,
     *     period_start_to?: string,
     *     period_end_from?: string,
     *     period_end_to?: string,
     *     page?: int,
     *     page_size?: int
     *     }  $query
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     *
     * @see https://medicaleventsmisapi.docs.apiary.io/#reference/medical-events/patient-summary/get-short-episodes-by-search-params
     */
    public function getShortEpisodes(string $patientId, array $query = []): PromiseInterface|EHealthResponse
    {
        $this->setReadContract('GET /api/patients/{patient_id}/summary/episodes', true);

        $this->setDefaultPageSize();

        $mergedQuery = array_merge($this->options['query'], $query ?? []);

        return $this->get(self::URL . "/$patientId/summary/episodes", $mergedQuery);
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
