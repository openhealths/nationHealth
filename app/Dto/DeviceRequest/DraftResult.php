<?php

declare(strict_types=1);

namespace App\Dto\DeviceRequest;

use App\Enums\Person\DeviceRequestStatus;

/** Preserve the accepted standalone device document separately from job metadata. */
final readonly class DraftResult
{
    public function __construct(public array $response, public array $resolved)
    {
    }

    public function uuid(): ?string
    {
        $resolved = $this->resolved['data'] ?? $this->resolved;
        $id = $resolved['id'] ?? $resolved['device_request_request']['id'] ?? $this->document()['id'] ?? null;

        return is_string($id) && $id !== '' ? $id : null;
    }

    public function document(): array
    {
        $resolved = $this->resolved['data'] ?? $this->resolved;
        if (isset($resolved['device_request_request']) && is_array($resolved['device_request_request'])) {
            return $resolved['device_request_request'];
        }

        $status = DeviceRequestStatus::resolve((string) ($resolved['status'] ?? ''));
        if (isset($resolved['code']) || isset($resolved['person_id']) || isset($resolved['quantity'])
            || in_array($status, [DeviceRequestStatus::NEW, DeviceRequestStatus::DRAFT, DeviceRequestStatus::ACTIVE], true)) {
            return $resolved;
        }

        $original = $this->response['data'] ?? $this->response;
        if (isset($original['device_request_request']) && is_array($original['device_request_request'])) {
            return $original['device_request_request'];
        }

        if (isset($original['code']) || isset($original['person_id']) || isset($original['quantity'])
            || ((isset($original['id']) || isset($original['uuid'])) && !isset($original['job_id']) && !isset($original['links']))) {
            return $original;
        }

        // A job identifier is not a clinical document and must never be passed to KEP.
        return [];
    }
}
