<?php

declare(strict_types=1);

namespace App\Classes\eHealth\Api\Patient;

use App\Classes\eHealth\EHealthResponse;
use GuzzleHttp\Promise\PromiseInterface;

class DeviceRequest extends PatientApiBase
{
    /**
     * Create a signed Device Request in eHealth (PKCS#7).
     *
     * @see REST API Create Device Request [API-007-020-0003]
     */
    public function createSigned(string $patientId, array $payload): PromiseInterface|EHealthResponse
    {
        $this->setReadContract('POST /api/patients/{patient_id}/device_requests', false);

        return $this->post(self::URL . "/{$patientId}/device_requests", $payload);
    }

    /**
     * Compatibility alias for the documented signed mark-in-error operation.
     */
    public function cancel(string $patientId, string $id, array $payload): PromiseInterface|EHealthResponse
    {
        return $this->markInError($patientId, $id, $payload);
    }

    /**
     * Get a specific Device Request by ID.
     */
    public function getById(string $patientId, string $id, array $query = []): PromiseInterface|EHealthResponse
    {
        $this->setReadContract('GET /api/patients/{patient_id}/device_requests/{device_request_id}', false);

        return $this->get(self::URL . "/{$patientId}/device_requests/{$id}", $query);
    }

    /**
     * Get Device Requests by search parameters in patient context.
     *
     * @param  string  $patientId
     * @param  array  $query
     * @return PromiseInterface|EHealthResponse
     *
     * @see REST API Get Device Requests by Search Params [API-007-020-0002]
     */
    public function getBySearchParams(string $patientId, array $query = []): PromiseInterface|EHealthResponse
    {
        $this->setReadContract('GET /api/patients/{patient_id}/device_requests', false);

        $this->setDefaultPageSize();

        $query = array_merge($this->options['query'], $query);

        return $this->get(self::URL . "/{$patientId}/device_requests", $query);
    }

    /**
     * Pre-qualify device request data before creation.
     *
     * @see REST API PreQualify Device Request [API-007-020-0009]
     */
    public function prequalify(string $patientId, array $payload): PromiseInterface|EHealthResponse
    {
        $this->setReadContract('POST /api/patients/{patient_id}/device_requests/prequalify', false);

        return $this->post(self::URL . "/{$patientId}/device_requests/prequalify", $payload);
    }

    /**
     * Resend SMS with OTP for an active device request.
     *
     * @see REST API Resend SMS on Device Request [API-007-020-0005]
     */
    public function resendSms(string $patientId, string $id): PromiseInterface|EHealthResponse
    {
        $this->setReadContract('PATCH /api/patients/{patient_id}/device_requests/{device_request_id}/actions/resend', false);

        return $this->patch(self::URL . "/{$patientId}/device_requests/{$id}/actions/resend", []);
    }

    public function revoke(string $patientId, string $id, array $payload): PromiseInterface|EHealthResponse
    {
        $this->setReadContract('PATCH /api/patients/{patient_id}/device_requests/{device_request_id}/actions/revoke', false);

        return $this->patch(self::URL . "/{$patientId}/device_requests/{$id}/actions/revoke", $payload);
    }

    public function complete(string $patientId, string $id): PromiseInterface|EHealthResponse
    {
        $this->setReadContract('PATCH /api/patients/{patient_id}/device_requests/{device_request_id}/actions/complete', false);

        return $this->patch(self::URL . "/{$patientId}/device_requests/{$id}/actions/complete", []);
    }

    public function markInError(string $patientId, string $id, array $payload): PromiseInterface|EHealthResponse
    {
        $this->setReadContract('PATCH /api/patients/{patient_id}/device_requests/{device_request_id}/actions/mark_in_error', false);

        return $this->patch(self::URL . "/{$patientId}/device_requests/{$id}/actions/mark_in_error", $payload);
    }

    public function getDispenses(string $patientId, array $query = []): PromiseInterface|EHealthResponse
    {
        $this->setReadContract('GET /api/patients/{patient_id}/device_dispenses', false);

        $this->setDefaultPageSize();

        return $this->get(self::URL . "/{$patientId}/device_dispenses", array_merge($this->options['query'], $query));
    }

    public function getDispense(string $patientId, string $id): PromiseInterface|EHealthResponse
    {
        $this->setReadContract('GET /api/patients/{patient_id}/device_dispenses/{device_dispense_id}', false);

        return $this->get(self::URL . "/{$patientId}/device_dispenses/{$id}");
    }
}
