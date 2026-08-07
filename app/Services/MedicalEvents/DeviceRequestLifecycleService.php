<?php

declare(strict_types=1);

namespace App\Services\MedicalEvents;

use App\Classes\eHealth\Api\DeviceRequest;
use Exception;
use Illuminate\Support\Facades\Log;

class DeviceRequestLifecycleService
{
    /**
     * PreQualify Device Request.
     *
     * @param  array  $payload
     * @return array
     */
    public function preQualify(array $payload): array
    {
        try {
            $response = DeviceRequest::preQualify($payload);

            return $response['data'] ?? $response;
        } catch (Exception $e) {
            Log::error('Device Request Prequalify failed: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Create Device Request (Draft).
     *
     * @param  array  $payload
     * @return array
     */
    public function createDraft(array $payload): array
    {
        try {
            $response = DeviceRequest::createDeviceRequest($payload);

            return $response['data'] ?? $response;
        } catch (Exception $e) {
            Log::error('Device Request Create Draft failed: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Sign Device Request.
     *
     * @param  string  $id
     * @param  array  $payload  (contains signed_content)
     * @return array
     */
    public function sign(string $id, array $payload): array
    {
        try {
            if (isset($payload['signed_content']) && !isset($payload['signed_device_request_request'])) {
                $payload['signed_device_request_request'] = $payload['signed_content'];
                unset($payload['signed_content']);
            }
            if (!isset($payload['signed_content_encoding'])) {
                $payload['signed_content_encoding'] = 'base64';
            }

            $response = DeviceRequest::signDeviceRequest($id, $payload);

            return $response['data'] ?? $response;
        } catch (Exception $e) {
            Log::error('Device Request Sign failed: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Reject Device Request.
     *
     * @param  string  $id
     * @param  array  $payload
     * @return array
     */
    public function reject(string $id, array $payload): array
    {
        try {
            $response = DeviceRequest::rejectDeviceRequest($id, $payload);

            return $response['data'] ?? $response;
        } catch (Exception $e) {
            Log::error('Device Request Reject failed: ' . $e->getMessage());
            throw $e;
        }
    }
}
