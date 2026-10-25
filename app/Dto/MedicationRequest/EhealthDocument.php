<?php

declare(strict_types=1);

namespace App\Dto\MedicationRequest;

/** Unwrap an opaque clinical document without removing unknown fields. */
final class EhealthDocument
{
    public static function unwrap(mixed $response): array
    {
        if (!is_array($response) || $response === []) {
            return [];
        }

        if (isset($response['id']) || isset($response['uuid'])) {
            return $response;
        }

        $data = $response['data'] ?? null;
        if (is_array($data) && (isset($data['id']) || isset($data['uuid']))) {
            return $data;
        }

        if (isset($data[0]) && is_array($data[0])) {
            return $data[0];
        }

        if (isset($response[0]) && is_array($response[0])) {
            return $response[0];
        }

        return [];
    }
}
