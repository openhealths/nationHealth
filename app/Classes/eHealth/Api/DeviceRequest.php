<?php

declare(strict_types=1);

namespace App\Classes\eHealth\Api;

use App\Classes\eHealth\EHealth;
use App\Classes\eHealth\Exceptions\ApiException;

class DeviceRequest extends EHealth
{
    /**
     * PreQualify Device Request (API-008-002).
     *
     * @param  array  $payload
     * @return array
     * @throws ApiException
     */
    public static function preQualify(array $payload): array
    {
        return self::request(
            'POST',
            '/api/prequalify_device_requests',
            self::getHeaders(),
            $payload
        );
    }

    /**
     * Create Device Request (API-008-003).
     * Creates a draft (NEW) Device Request.
     *
     * @param  array  $payload
     * @return array
     * @throws ApiException
     */
    public static function createDeviceRequest(array $payload): array
    {
        return self::request(
            'POST',
            '/api/device_requests',
            self::getHeaders(),
            $payload
        );
    }

    /**
     * Sign Device Request (API-008-004).
     * Signs the draft and makes it ACTIVE.
     *
     * @param  string  $id
     * @param  array  $payload
     * @return array
     * @throws ApiException
     */
    public static function signDeviceRequest(string $id, array $payload): array
    {
        if (isset($payload['signed_content']) && !isset($payload['signed_device_request_request'])) {
            $payload['signed_device_request_request'] = $payload['signed_content'];
            unset($payload['signed_content']);
        }
        if (!isset($payload['signed_content_encoding'])) {
            $payload['signed_content_encoding'] = 'base64';
        }

        return self::request(
            'PATCH',
            '/api/device_requests/' . $id . '/sign',
            self::getHeaders(),
            $payload
        );
    }

    /**
     * Reject Device Request (API-008-005).
     * Rejects an active device request.
     *
     * @param  string  $id
     * @param  array  $payload
     * @return array
     * @throws ApiException
     */
    public static function rejectDeviceRequest(string $id, array $payload): array
    {
        return self::request(
            'PATCH',
            '/api/device_requests/' . $id . '/actions/reject',
            self::getHeaders(),
            $payload
        );
    }

    /**
     * Get Device Request by ID (API-008-006).
     *
     * @param  string  $id
     * @return array
     * @throws ApiException
     */
    public static function getDeviceRequest(string $id): array
    {
        return self::request(
            'GET',
            '/api/device_requests/' . $id,
            self::getHeaders()
        );
    }
}
