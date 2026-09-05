<?php

declare(strict_types=1);

namespace App\Services\MedicalEvents;

use App\Classes\eHealth\Api\DeviceRequest;
use App\Contracts\EHealthRequestLifecycleContract;

class DeviceRequestLifecycleService extends EHealthRequestLifecycleService implements EHealthRequestLifecycleContract
{
    public function preQualify(array $payload): array
    {
        return $this->callEHealth('Prequalify', static fn (): array => app(DeviceRequest::class)->preQualify($payload)->getData());
    }

    public function createDraft(array $payload): array
    {
        return $this->callEHealth('Create Draft', static fn (): array => app(DeviceRequest::class)->createDeviceRequest($payload)->getData());
    }

    public function sign(string $id, array $payload): array
    {
        $payload = $this->normalizeSignedPayload($payload);

        return $this->callEHealth('Sign', static fn (): array => app(DeviceRequest::class)->signDeviceRequest($id, $payload)->getData());
    }

    public function reject(string $id, array $payload): array
    {
        return $this->callEHealth('Reject', static fn (): array => app(DeviceRequest::class)->rejectDeviceRequest($id, $payload)->getData());
    }

    protected function requestType(): string
    {
        return 'Device Request';
    }

    /**
     * Device Request expects the KEP blob under signed_device_request_request.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function normalizeSignedPayload(array $payload): array
    {
        if (isset($payload['signed_content']) && !isset($payload['signed_device_request_request'])) {
            $payload['signed_device_request_request'] = $payload['signed_content'];
            unset($payload['signed_content']);
        }

        $payload['signed_content_encoding'] ??= 'base64';

        return $payload;
    }
}
