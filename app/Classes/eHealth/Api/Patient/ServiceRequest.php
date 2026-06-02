<?php

declare(strict_types=1);

namespace App\Classes\eHealth\Api\Patient;

use App\Classes\eHealth\EHealthResponse;
use App\Exceptions\EHealth\EHealthConnectionException;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Exceptions\EHealth\EHealthValidationException;
use GuzzleHttp\Promise\PromiseInterface;

class ServiceRequest extends PatientApiBase
{
    /**
     * Create a Service Request Request (Заявка на направлення послуги).
     */
    public function createRequest(string $patientId, array $payload): PromiseInterface|EHealthResponse
    {
        return $this->post(self::URL . "/{$patientId}/service_request_requests", $payload);
    }

    /**
     * Sign a Service Request Request (Підпис заявки КЕП).
     */
    public function signRequest(string $patientId, string $requestId, array $payload): PromiseInterface|EHealthResponse
    {
        return $this->patch(self::URL . "/{$patientId}/service_request_requests/{$requestId}/actions/sign", $payload);
    }

    /**
     * Cancel a Service Request (Скасування направлення).
     */
    public function cancel(string $patientId, string $id, array $payload): PromiseInterface|EHealthResponse
    {
        return $this->patch(self::URL . "/{$patientId}/service_requests/{$id}/actions/cancel", $payload);
    }

    /**
     * Get a specific Service Request by ID.
     */
    public function getById(string $patientId, string $id, array $query = []): PromiseInterface|EHealthResponse
    {
        return $this->get(self::URL . "/{$patientId}/service_requests/{$id}", $query);
    }
}
