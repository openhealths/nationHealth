<?php

declare(strict_types=1);

namespace App\Classes\eHealth\Api;

use App\Classes\eHealth\EHealthRequest;
use App\Classes\eHealth\EHealthResponse;
use App\Exceptions\EHealth\EHealthConnectionException;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Exceptions\EHealth\EHealthValidationException;
use GuzzleHttp\Promise\PromiseInterface;

class ServiceRequest extends EHealthRequest
{
    protected const string ENDPOINT_SERVICE_REQUESTS = '/api/service_requests';
    protected const string ENDPOINT_MEDICAL_EVENTS_SERVICE_REQUESTS = '/api/medical_events/service_requests';

    /**
     * Create Draft Service Request.
     */
    public function createDraft(array $payload): PromiseInterface|EHealthResponse
    {
        return $this->post(self::ENDPOINT_SERVICE_REQUESTS, $payload);
    }

    /**
     * Sign Service Request (KEП).
     */
    public function signRequest(string $id, array $payload): PromiseInterface|EHealthResponse
    {
        return $this->patch(self::ENDPOINT_SERVICE_REQUESTS . "/{$id}/sign", $payload);
    }

    /**
     * Reject Service Request.
     */
    public function reject(string $id, array $payload = []): PromiseInterface|EHealthResponse
    {
        return $this->patch(self::ENDPOINT_SERVICE_REQUESTS . "/{$id}/actions/reject", $payload);
    }

    /**
     * Discover service requests by requisition number.
     */
    public function searchForServiceRequestsByParams(array $params): PromiseInterface|EHealthResponse
    {
        return $this->get(self::ENDPOINT_SERVICE_REQUESTS, $params);
    }

    /**
     * Qualify a Service Request.
     */
    public function qualify(string $id, array $payload = []): PromiseInterface|EHealthResponse
    {
        return $this->post(self::ENDPOINT_SERVICE_REQUESTS . "/{$id}/actions/qualify", $payload);
    }

    /**
     * Process a Service Request (взяття в роботу / Use Service Request).
     */
    public function process(string $id, array $payload = []): PromiseInterface|EHealthResponse
    {
        return $this->patch(self::ENDPOINT_SERVICE_REQUESTS . "/{$id}/actions/use", $payload);
    }

    /**
     * Complete a Service Request (погашення).
     */
    public function complete(string $id, array $payload = []): PromiseInterface|EHealthResponse
    {
        return $this->patch(self::ENDPOINT_SERVICE_REQUESTS . "/{$id}/actions/complete", $payload);
    }

    /**
     * Cancel usage of a Service Request (відміна використання).
     */
    public function cancelUsage(string $id, string $patientId, array $payload = []): PromiseInterface|EHealthResponse
    {
        return $this->patch("/api/patients/{$patientId}/service_requests/{$id}/actions/cancel", $payload);
    }

    /**
     * Recall a Service Request (відміна за непотрібністю, TV 3.17.1.13).
     */
    public function recall(string $patientId, string $id, array $payload = []): PromiseInterface|EHealthResponse
    {
        return $this->patch("/api/patients/{$patientId}/service_requests/{$id}/actions/recall", $payload);
    }
}
