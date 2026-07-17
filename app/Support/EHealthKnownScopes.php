<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Scopes recognized by this MIS from config/scopes (AR Scopes model).
 * Anything outside this set is ignored on login tokens and stripped from authorize.
 */
final class EHealthKnownScopes
{
    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return collect(config('ehealth.roles'))
            ->flatten()
            ->filter(static fn (mixed $scope): bool => is_string($scope) && $scope !== '')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  list<string>  $scopes
     * @return list<string>
     */
    public static function filter(array $scopes): array
    {
        $known = self::all();

        return array_values(array_filter(
            $scopes,
            static fn (string $scope): bool => $scope !== '' && in_array($scope, $known, true)
        ));
    }
}
