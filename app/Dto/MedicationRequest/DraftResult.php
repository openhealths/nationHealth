<?php

declare(strict_types=1);

namespace App\Dto\MedicationRequest;

/** The accepted document stays opaque: it is stored and signed without DTO serialization. */
final readonly class DraftResult
{
    public function __construct(public array $response, public array $resolved)
    {
    }

    public function uuid(string $fallback): string
    {
        return $this->resolved['id'] ?? $this->resolved['data']['id'] ?? $fallback;
    }

    public function requestNumber(): ?string
    {
        return $this->resolved['request_number'] ?? $this->resolved['requisition'] ?? $this->resolved['data']['request_number'] ?? null;
    }

    public function document(): array
    {
        foreach ([$this->resolved, $this->response] as $candidate) {
            if (isset($candidate['data'])) {
                return is_array($candidate['data']) ? $candidate['data'] : (array) $candidate['data'];
            }

            if (isset($candidate['person']) || isset($candidate['based_on'])) {
                return $candidate;
            }
        }

        return $this->resolved;
    }
}
