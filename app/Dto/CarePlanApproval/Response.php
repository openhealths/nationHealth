<?php

declare(strict_types=1);

namespace App\Dto\CarePlanApproval;

use Illuminate\Support\Collection;
use Symfony\Component\ObjectMapper\Attribute\Map;

#[Map(source: Collection::class)]
final class Response
{
    #[Map(source: '[id?]', transform: [self::class, 'approvalId'])]
    public ?string $approvalId;

    #[Map(source: '[authentication_method_current?]', transform: [self::class, 'authMethod'])]
    public ?array $authMethod;

    #[Map(source: '[is_verified?]', transform: [self::class, 'verified'])]
    public ?bool $isVerified;

    public static function approvalId(mixed $value, Collection $source): ?string
    {
        $data = $source->all();
        $id = $data['response_data']['id']
            ?? $data['data']['id']
            ?? $data['id']
            ?? null;

        return is_string($id) || is_numeric($id) ? (string) $id : null;
    }

    public static function authMethod(mixed $value, Collection $source): ?array
    {
        $data = $source->all();
        $method = $data['response_data']['authentication_method_current']
            ?? $data['data']['authentication_method_current']
            ?? $data['authentication_method_current']
            ?? $data['urgent']['authentication_method_current']
            ?? null;

        return is_array($method) ? $method : null;
    }

    public static function verified(mixed $value, Collection $source): ?bool
    {
        $data = $source->all();
        $candidates = [
            $data['response']['body']['data']['is_verified'] ?? null,
            $data['response_data']['is_verified'] ?? null,
            $data['data']['is_verified'] ?? null,
            $data['is_verified'] ?? null,
            $data['urgent']['is_verified'] ?? null,
            $data['response']['data']['is_verified'] ?? null,
        ];

        foreach ($candidates as $value) {
            if (is_bool($value)) {
                return $value;
            }

            if ($value === 0 || $value === 1 || $value === '0' || $value === '1') {
                return (bool) $value;
            }

            if ($value === 'true' || $value === 'false') {
                return $value === 'true';
            }
        }

        return null;
    }
}
