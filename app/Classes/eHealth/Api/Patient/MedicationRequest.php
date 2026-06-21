<?php

declare(strict_types=1);

namespace App\Classes\eHealth\Api\Patient;

use App\Classes\eHealth\EHealthResponse;
use App\Exceptions\EHealth\EHealthConnectionException;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Exceptions\EHealth\EHealthValidationException;
use GuzzleHttp\Promise\PromiseInterface;

class MedicationRequest extends PatientApiBase
{
    /**
     * Create a Medication Request Request (Заявка на виписування рецепту).
     *
     * @param  string  $patientId
     * @param  array  $payload
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     */
    public function createRequest(string $patientId, array $payload): PromiseInterface|EHealthResponse
    {
        return $this->post(self::URL . "/{$patientId}/medication_request_requests", $payload);
    }

    /**
     * Sign a Medication Request Request (Підпис заявки КЕП).
     *
     * @param  string  $patientId
     * @param  string  $requestId
     * @param  array  $payload
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     */
    public function signRequest(string $patientId, string $requestId, array $payload): PromiseInterface|EHealthResponse
    {
        return $this->patch(self::URL . "/{$patientId}/medication_request_requests/{$requestId}/actions/sign", $payload);
    }

    /**
     * Cancel a Medication Request (Скасування рецепту).
     *
     * @param  string  $patientId
     * @param  string  $id
     * @param  array  $payload  Requires 'status_reason' and optional KEP signature info
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     */
    public function cancel(string $patientId, string $id, array $payload): PromiseInterface|EHealthResponse
    {
        return $this->patch(self::URL . "/{$patientId}/medication_requests/{$id}/actions/cancel", $payload);
    }

    /**
     * Get a specific Medication Request by ID.
     *
     * @param  string  $patientId
     * @param  string  $id
     * @param  array  $query
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     */
    public function getById(string $patientId, string $id, array $query = []): PromiseInterface|EHealthResponse
    {
        return $this->get(self::URL . "/{$patientId}/medication_requests/{$id}", $query);
    }

    /**
     * Get Medication Requests by search parameters.
     *
     * @param  string  $patientId
     * @param  array  $query
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     */
    public function getBySearchParams(string $patientId, array $query = []): PromiseInterface|EHealthResponse
    {
        $this->setDefaultPageSize();
        $mergedQuery = array_merge($this->options['query'], $query);

        return $this->get(self::URL . "/{$patientId}/medication_requests", $mergedQuery);
    }

    /**
     * Get Medication Request Requests by search parameters.
     *
     * @param  string  $patientId
     * @param  array  $query
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     */
    public function getRequestsBySearchParams(string $patientId, array $query = []): PromiseInterface|EHealthResponse
    {
        $this->setDefaultPageSize();
        $mergedQuery = array_merge($this->options['query'], $query);

        return $this->get(self::URL . "/{$patientId}/medication_request_requests", $mergedQuery);
    }

    /**
     * Resend SMS code for Medication Request.
     *
     * @param  string  $patientId
     * @param  string  $id
     * @return PromiseInterface|\App\Classes\eHealth\EHealthResponse
     */
    public function resendOtp(string $patientId, string $id): PromiseInterface|\App\Classes\eHealth\EHealthResponse
    {
        return $this->post(self::URL . "/{$patientId}/medication_requests/{$id}/actions/resend_otp", []);
    }
}
