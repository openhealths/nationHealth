<?php

declare(strict_types=1);

namespace App\Classes\eHealth\Api;

use App\Classes\eHealth\Exceptions\ApiException;
use App\Classes\eHealth\Request;
use Symfony\Component\HttpFoundation\Request as RequestHttp;

class ServiceRequest
{
    protected const string ENDPOINT_SERVICE_REQUESTS = '/api/service_requests';
    protected const string ENDPOINT_MEDICAL_EVENTS_SERVICE_REQUESTS = '/api/medical_events/service_requests';

    /**
     * Create Draft Service Request.
     *
     * @param  array  $payload
     * @return array
     * @throws ApiException
     */
    public static function createDraft(array $payload): array
    {
        return new Request(RequestHttp::METHOD_POST, self::ENDPOINT_SERVICE_REQUESTS, $payload)->sendRequest();
    }

    /**
     * Sign Service Request (KEП).
     *
     * @param  string  $id
     * @param  array  $payload
     * @return array
     * @throws ApiException
     */
    public static function sign(string $id, array $payload): array
    {
        return new Request(RequestHttp::METHOD_PATCH, self::ENDPOINT_SERVICE_REQUESTS . "/{$id}/sign", $payload)->sendRequest();
    }

    /**
     * Reject Service Request.
     *
     * @param  string  $id
     * @param  array  $payload
     * @return array
     * @throws ApiException
     */
    public static function reject(string $id, array $payload = []): array
    {
        return new Request(RequestHttp::METHOD_PATCH, self::ENDPOINT_SERVICE_REQUESTS . "/{$id}/actions/reject", $payload)->sendRequest();
    }

    /**
     * Discover service requests by requisition number. If nothing found by requisition number - it will return empty list.
     *
     * @param  array  $params
     * @return array
     * @throws ApiException
     */
    public static function searchForServiceRequestsByParams(array $params): array
    {
        return new Request(RequestHttp::METHOD_GET, self::ENDPOINT_SERVICE_REQUESTS, $params)->sendRequest();
    }

    /**
     * Qualify a Service Request.
     *
     * @param  string  $id
     * @param  array  $payload
     * @return array
     * @throws ApiException
     */
    public static function qualify(string $id, array $payload = []): array
    {
        return new Request(RequestHttp::METHOD_POST, self::ENDPOINT_SERVICE_REQUESTS . "/{$id}/actions/qualify", $payload)->sendRequest();
    }

    /**
     * Process a Service Request (взяття в роботу / Use Service Request).
     *
     * @param  string  $id
     * @param  array  $payload
     * @return array
     * @throws ApiException
     */
    public static function process(string $id, array $payload = []): array
    {
        return new Request(RequestHttp::METHOD_PATCH, self::ENDPOINT_SERVICE_REQUESTS . "/{$id}/actions/use", $payload)->sendRequest();
    }

    /**
     * Complete a Service Request (погашення).
     *
     * @param  string  $id
     * @param  array  $payload
     * @return array
     * @throws ApiException
     */
    public static function complete(string $id, array $payload = []): array
    {
        return new Request(RequestHttp::METHOD_PATCH, self::ENDPOINT_SERVICE_REQUESTS . "/{$id}/actions/complete", $payload)->sendRequest();
    }

    /**
     * Cancel usage of a Service Request (відміна використання).
     *
     * @param  string  $id
     * @param  string  $patientId
     * @param  array  $payload
     * @return array
     * @throws ApiException
     */
    public static function cancelUsage(string $id, string $patientId, array $payload = []): array
    {
        return new Request(RequestHttp::METHOD_PATCH, "/api/patients/{$patientId}/service_requests/{$id}/actions/cancel", $payload)->sendRequest();
    }
}
