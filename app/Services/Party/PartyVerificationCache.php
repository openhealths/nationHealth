<?php

declare(strict_types=1);

namespace App\Services\Party;

use Illuminate\Support\Facades\Cache;

final class PartyVerificationCache
{
    public const string KEY_PREFIX = 'party_verification_details:';

    public const int TTL_SECONDS = 86400;

    /**
     * @return array{verification_status: mixed, details: array<string, array{verification_status: mixed}>}
     */
    public static function payloadFromApiData(array $data): array
    {
        return [
            'verification_status' => data_get($data, 'verification_status'),
            'details' => [
                'drfo' => [
                    'verification_status' => data_get($data, 'details.drfo.verification_status'),
                ],
                'dracs_death' => [
                    'verification_status' => data_get($data, 'details.dracs_death.verification_status'),
                ],
                'dms_passport' => [
                    'verification_status' => data_get($data, 'details.dms_passport.verification_status'),
                ],
            ],
        ];
    }

    public static function put(string $partyUuid, array $apiData): void
    {
        Cache::put(self::KEY_PREFIX . $partyUuid, self::payloadFromApiData($apiData), self::TTL_SECONDS);
    }

    /**
     * @return array{verification_status: mixed, details: array<string, array{verification_status: mixed}>}|null
     */
    public static function get(string $partyUuid): ?array
    {
        $cached = Cache::get(self::KEY_PREFIX . $partyUuid);

        return is_array($cached) ? $cached : null;
    }
}
