<?php

declare(strict_types=1);

namespace App\Services\Dictionary;

/**
 * Picks the default medical program for standalone service referrals.
 */
final class ServiceProgramPicker
{
    /**
     * Prefer the state medical guarantees program when it is in the list, otherwise the first one.
     *
     * @param  list<array{id?: string, name?: string}>  $programs
     */
    public static function defaultId(array $programs): string
    {
        $needles = array_values(array_filter([
            mb_strtolower((string) __('care-plan.state_financial_guarantees')),
            'фінансових гарантій',
        ]));

        $fallback = '';

        foreach ($programs as $program) {
            if (!is_array($program)) {
                continue;
            }

            $id = trim((string) ($program['id'] ?? ''));
            if ($id === '') {
                continue;
            }

            if ($fallback === '') {
                $fallback = $id;
            }

            $name = mb_strtolower((string) ($program['name'] ?? ''));
            foreach ($needles as $needle) {
                if ($needle !== '' && str_contains($name, $needle)) {
                    return $id;
                }
            }
        }

        return $fallback;
    }
}
