<?php

declare(strict_types=1);

namespace App\Classes\eHealth\Api;

use App\Classes\eHealth\EHealthRequest as Request;
use App\Classes\eHealth\EHealthResponse;
use GuzzleHttp\Promise\PromiseInterface;

class ServiceRequest extends Request
{
    protected const string ENDPOINT_SERVICE_REQUESTS = '/api/service_requests';
    protected const string ENDPOINT_MEDICAL_EVENTS_SERVICE_REQUESTS = '/api/medical_events/service_requests';

    public function buildUseContent(array $context, mixed $programId): array
    {
        $payload = [];
        $references = ['used_by_employee' => ['employee_uuid', 'employee'], 'used_by_division' => ['division_uuid', 'division'], 'used_by_legal_entity' => ['legal_entity_uuid', 'legal_entity']];
        if ($programId !== null && $programId !== '') {
            $context['program_uuid'] = (string) $programId;
            $references['program'] = ['program_uuid', 'medical_program'];
        }
        foreach ($references as $field => [$key, $type]) {
            if (!empty($context[$key])) {
                $payload[$field] = ['identifier' => ['type' => ['coding' => [['system' => 'eHealth/resources', 'code' => $type]]], 'value' => $context[$key]]];
            }
        }

        return $payload;
    }

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
        throw new \LogicException('Cancelling usage has no verified PROD endpoint. A clinical Cancel requires a signed resource and cannot be used as Release.');
    }

    /**
     * Recall a Service Request (відміна за непотрібністю, TV 3.17.1.13).
     */
    public function recall(string $patientId, string $id, array $payload = []): PromiseInterface|EHealthResponse
    {
        return $this->patch("/api/patients/{$patientId}/service_requests/{$id}/actions/recall", $payload);
    }
}
