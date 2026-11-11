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

class DetectedIssue extends PatientApiBase
{
    /**
     * Get detected issues by search params.
     *
     * @param  string  $patientId
     * @param array{
     *     encounter_id?: string,
     *     episode_id?: string,
     *     status?: string,
     *     recorder?: string,
     *     recorder_legal_entity_id?: string,
     *     device_id?: string,
     *     inserted_at_from?: string,
     *     inserted_at_to?: string,
     *     identified_date_time_from?: string,
     *     identified_date_time_to?: string,
     *     page?: int,
     *     page_size?: int
     * } $query
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     *
     * @see https://medicaleventsmisapi.docs.apiary.io/#reference/medical-events/detected-issue/get-detected-issues-by-search-params
     */
    public function getBySearchParams(string $patientId, array $query = []): PromiseInterface|EHealthResponse
    {
        $this->setReadContract('GET /api/patients/{patient_id}/detected_issues');

        $this->setDefaultPageSize();

        $mergedQuery = array_merge(
            $this->options['query'],
            $this->format($query, ['inserted_at_from', 'inserted_at_to'])
        );

        return $this->get(self::URL . "/$patientId/detected_issues", $mergedQuery);
    }

    /**
     * Return detail data by ID.
     *
     * @param  string  $patientId
     * @param  string  $detectedIssueId
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     *
     * @see https://medicaleventsmisapi.docs.apiary.io/#reference/medical-events/detected-issue/get-detected-issue-by-id
     */
    public function getById(string $patientId, string $detectedIssueId): PromiseInterface|EHealthResponse
    {
        $this->setReadContract('GET /api/patients/{patient_id}/detected_issues/{detected_issue_id}');

        return $this->get(self::URL . "/$patientId/detected_issues/$detectedIssueId");
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
