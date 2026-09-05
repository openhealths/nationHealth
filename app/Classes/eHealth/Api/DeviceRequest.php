<?php

declare(strict_types=1);

namespace App\Classes\eHealth\Api;

use App\Classes\eHealth\EHealthRequest as Request;
use App\Classes\eHealth\EHealthResponse;
use App\Exceptions\EHealth\EHealthConnectionException;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Exceptions\EHealth\EHealthValidationException;
use GuzzleHttp\Promise\PromiseInterface;

class DeviceRequest extends Request
{
    /**
     * PreQualify Device Request (API-008-002).
     *
     * @param  array  $payload
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     */
    public function preQualify(array $payload): PromiseInterface|EHealthResponse
    {
        return $this->post('/api/prequalify_device_requests', $payload);
    }

    /**
     * Create Device Request (API-008-003).
     * Creates a draft (NEW) Device Request.
     *
     * @param  array  $payload
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     */
    public function createDeviceRequest(array $payload): PromiseInterface|EHealthResponse
    {
        return $this->post('/api/device_requests', $payload);
    }

    /**
     * Sign Device Request (API-008-004).
     * Signs the draft and makes it ACTIVE.
     *
     * @param  string  $id
     * @param  array  $payload
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     */
    public function signDeviceRequest(string $id, array $payload): PromiseInterface|EHealthResponse
    {
        if (isset($payload['signed_content']) && !isset($payload['signed_device_request_request'])) {
            $payload['signed_device_request_request'] = $payload['signed_content'];
            unset($payload['signed_content']);
        }
        if (!isset($payload['signed_content_encoding'])) {
            $payload['signed_content_encoding'] = 'base64';
        }

        return $this->patch('/api/device_requests/' . $id . '/sign', $payload);
    }

    /**
     * Reject Device Request (API-008-005).
     * Rejects an active device request.
     *
     * @param  string  $id
     * @param  array  $payload
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     */
    public function rejectDeviceRequest(string $id, array $payload): PromiseInterface|EHealthResponse
    {
        return $this->patch('/api/device_requests/' . $id . '/actions/reject', $payload);
    }

    /**
     * Get Device Request by ID (API-008-006).
     *
     * @param  string  $id
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     */
    public function getDeviceRequest(string $id): PromiseInterface|EHealthResponse
    {
        return $this->get('/api/device_requests/' . $id);
    }
}
