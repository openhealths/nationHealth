<?php

declare(strict_types=1);

namespace App\Classes\eHealth\Api;

use App\Classes\eHealth\EHealth;
use App\Classes\eHealth\Exceptions\ApiException;

/**
 * eHealth API клієнт для погашення та обліку е-запитів на медичні вироби (п. 3.5.2 ТЗ)
 */
class DeviceDispense extends EHealth
{
    /**
     * 3.5.2.1: Search for a Device request
     */
    public static function searchDeviceRequest(array $query): array
    {
        return self::request(
            'GET',
            '/api/device_requests',
            self::getHeaders(),
            [],
            $query
        );
    }

    /**
     * 3.5.2.2: Qualify Device Request
     */
    public static function qualifyDeviceRequest(string $id, array $payload): array
    {
        return self::request(
            'POST',
            '/api/device_requests/' . $id . '/qualify',
            self::getHeaders(),
            $payload
        );
    }

    /**
     * 3.5.2.3: Create Device dispense (блокування на 20 хв)
     */
    public static function createDeviceDispense(array $payload): array
    {
        return self::request(
            'POST',
            '/api/device_dispenses',
            self::getHeaders(),
            $payload
        );
    }

    /**
     * 3.5.2.3.4: Stop Device Dispense
     */
    public static function stopDeviceDispense(string $id, array $payload = []): array
    {
        return self::request(
            'PATCH',
            '/api/device_dispenses/' . $id . '/actions/stop',
            self::getHeaders(),
            $payload
        );
    }

    /**
     * 3.5.2.3.6: Complete Device dispense (підписання КЕП)
     */
    public static function completeDeviceDispense(string $id, array $payload): array
    {
        if (!isset($payload['signed_content_encoding'])) {
            $payload['signed_content_encoding'] = 'base64';
        }

        return self::request(
            'PATCH',
            '/api/device_dispenses/' . $id . '/actions/complete',
            self::getHeaders(),
            $payload
        );
    }

    /**
     * 3.5.2.4.1.1: Get Device dispenses by search params
     */
    public static function getDeviceDispenses(array $query = []): array
    {
        return self::request(
            'GET',
            '/api/device_dispenses',
            self::getHeaders(),
            [],
            $query
        );
    }

    /**
     * 3.5.2.4.1.2: Get Device dispense details
     */
    public static function getDeviceDispenseDetails(string $id): array
    {
        return self::request(
            'GET',
            '/api/device_dispenses/' . $id,
            self::getHeaders()
        );
    }
}
