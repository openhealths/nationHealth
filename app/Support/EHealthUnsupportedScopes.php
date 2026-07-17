<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Scopes that eHealth may still return on tokens / roles, but that must not be
 * requested in oauth/apps/authorize (eHealth rejects them) and must not be
 * stored as local permissions.
 */
final class EHealthUnsupportedScopes
{
    /**
     * @return list<string>
     */
    public static function names(): array
    {
        return [
            'party_verification:read',
        ];
    }

    public static function contains(string $scope): bool
    {
        return in_array($scope, self::names(), true);
    }

    /**
     * @param  list<string>  $scopes
     * @return list<string>
     */
    public static function filter(array $scopes): array
    {
        return array_values(array_filter(
            $scopes,
            static fn (string $scope): bool => $scope !== '' && !self::contains($scope)
        ));
    }
}
