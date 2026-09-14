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
        return $this->post(self::URL . "/{$patientId}/device_requests", $payload);
    }

    /**
     * Cancel a Device Request (Скасування направлення на виріб).
     */
    public function cancel(string $patientId, string $id, array $payload): PromiseInterface|EHealthResponse
    {
        return $this->patch(self::URL . "/{$patientId}/device_requests/{$id}/actions/cancel", $payload);
    }

    /**
     * Get a specific Device Request by ID.
     */
    public function getById(string $patientId, string $id, array $query = []): PromiseInterface|EHealthResponse
    {
        return $this->get(self::URL . "/{$patientId}/device_requests/{$id}", $query);
    }

    /**
     * Get Device Requests of the patient by search params.
     *
     * A dispense is issued against one of these, so the ones offered as `based_on` are read from here
     * rather than from the local drafts alone (TV 3.22.2.1).
     *
     * @param  array{
     *     status?: string,
     *     requester_legal_entity?: string,
     *     encounter_id?: string,
     *     episode_id?: string,
     *     device_definition_id?: string,
     *     page?: int,
     *     page_size?: int
     * }  $query
     */
    public function getBySearchParams(string $patientId, array $query = []): PromiseInterface|EHealthResponse
    {
        $this->setDefaultPageSize();

        $mergedQuery = array_merge($this->options['query'] ?? [], $query);

        return $this->get(self::URL . "/{$patientId}/device_requests", $mergedQuery);
    }

    /**
     * Pre-qualify device request data before creation.
     *
     * @see REST API PreQualify Device Request [API-007-020-0009]
     */
    public function prequalify(string $patientId, array $payload): PromiseInterface|EHealthResponse
    {
        return $this->post(self::URL . "/{$patientId}/device_requests/prequalify", $payload);
    }

    /**
     * Resend SMS with OTP for an active device request.
     *
     * @see REST API Resend SMS on Device Request [API-007-020-0005]
     */
    public function resendSms(string $patientId, string $id): PromiseInterface|EHealthResponse
    {
        return $this->post(self::URL . "/{$patientId}/device_requests/{$id}/actions/resend", []);
    }
}
