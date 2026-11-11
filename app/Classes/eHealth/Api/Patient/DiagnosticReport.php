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

class DiagnosticReport extends PatientApiBase
{
    /**
     * Create the diagnostic report for patient.
     *
     * @param  string  $uuid  Person UUID
     * @param  array  $data
     * @return EHealthResponse|PromiseInterface
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     *
     * @see https://medicaleventsmisapi.docs.apiary.io/#reference/medical-events/diagnostic-report-data-package/submit-diagnostic-report-package
     */
    public function create(string $uuid, array $data = []): PromiseInterface|EHealthResponse
    {
        $this->setValidator($this->validateSubmittedJob(...));

        return $this->post(self::URL . "/$uuid/diagnostic_report_package", $data);
    }

    /**
     * Cancel diagnostic report package by marking the diagnostic report and related observations as entered in error.
     *
     * @param  string  $uuid  Person UUID
     * @param  array  $data
     * @return EHealthResponse|PromiseInterface
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     *
     * @see https://medicaleventsmisapi.docs.apiary.io/#reference/medical-events/diagnostic-report-data-package/cancel-diagnostic-report-package
     */
    public function cancel(string $uuid, array $data = []): PromiseInterface|EHealthResponse
    {
        $this->setValidator($this->validateSubmittedJob(...));

        return $this->patch(self::URL . "/$uuid/diagnostic_report_package", $data);
    }

    /**
     * Get a diagnostic report by ID.
     *
     * @param  string  $patientId
     * @param  string  $diagnosticReportId
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     *
     * @see https://medicaleventsmisapi.docs.apiary.io/#reference/medical-events/diagnostic-report/get-diagnostic-report-by-id
     */
    public function getById(string $patientId, string $diagnosticReportId): PromiseInterface|EHealthResponse
    {
        $this->setReadContract('GET /api/patients/{patient_id}/diagnostic_reports/{diagnostic_report_id}');

        return $this->get(self::URL . "/$patientId/diagnostic_reports/$diagnosticReportId");
    }

    /**
     * Get a list of info filtered by search params.
     *
     * @param  string  $patientId
     * @param  array{
     *     code?: string,
     *     encounter_id?: string,
     *     context_episode_id?: string,
     *     origin_episode_id?: string,
     *     issued_from?: string,
     *     issued_to?: string,
     *     based_on?: string,
     *     managing_organization_id?: string,
     *     specimen_id?: string,
     *     page?: int,
     *     page_size?: int
     *     }  $query
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     *
     * @see https://medicaleventsmisapi.docs.apiary.io/#reference/medical-events/diagnostic-report/get-diagnostic-report-by-search-params
     */
    public function getBySearchParams(string $patientId, array $query = []): PromiseInterface|EHealthResponse
    {
        $this->setReadContract('GET /api/patients/{patient_id}/diagnostic_reports/');

        $this->setDefaultPageSize();

        $mergedQuery = array_merge($this->options['query'], $this->format($query, ['issued_from', 'issued_to']));

        return $this->get(self::URL . "/$patientId/diagnostic_reports", $mergedQuery);
    }

    /**
     * Get a list of summary info about diagnostic reports.
     *
     * @param  string  $patientId
     * @param  array{code?: string, issued_from?: string, issued_to?: string, page?: int, page_size?: int}  $query
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     *
     * @see https://medicaleventsmisapi.docs.apiary.io/#reference/medical-events/patient-summary/get-diagnostic-report-by-search-params
     */
    public function getSummary(string $patientId, array $query = []): PromiseInterface|EHealthResponse
    {
        $this->setReadContract('GET /api/patients/{patient_id}/summary/diagnostic_reports/');

        $this->setDefaultPageSize();

        $mergedQuery = array_merge($this->options['query'], $query ?? []);

        return $this->get(self::URL . "/$patientId/summary/diagnostic_reports", $mergedQuery);
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
