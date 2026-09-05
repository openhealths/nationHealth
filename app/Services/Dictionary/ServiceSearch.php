<?php

declare(strict_types=1);

namespace App\Services\Dictionary;

/**
 * Search and flatten the eHealth services dictionary tree for referral pickers.
 */
final class ServiceSearch
{
    /**
     * Search requestable services by code or text name.
     *
     * @param  callable(array<string, mixed>): list<array<string, mixed>>  $fetch
     * @return list<array<string, mixed>>
     */
    public static function search(string $query, callable $fetch, int $page = 1, int $pageSize = 15): array
    {
        $query = trim($query);
        if ($query === '') {
            return [];
        }

        $base = [
            'page' => $page,
            'page_size' => $pageSize,
        ];

        // 1. If query looks like a service code (digits, dashes, alpha-numeric code)
        if (self::isCodeQuery($query)) {
            foreach (self::codeCandidates($query) as $code) {
                try {
                    $raw = $fetch($base + ['code' => $code]);
                    $flattened = self::flattenRequestable($raw);
                    $filtered = self::filterByQueryNeedle($flattened, $query);
                    if ($filtered !== []) {
                        return $filtered;
                    }
                } catch (\Throwable) {
                }
            }
        }

        // 2. Search by full name / query
        try {
            $raw = $fetch($base + ['name' => $query]);
            $flattened = self::flattenRequestable($raw);
            $filtered = self::filterByQueryNeedle($flattened, $query);
            if ($filtered !== []) {
                return $filtered;
            }
        } catch (\Throwable) {
        }

        // 3. If multi-word query produced 0 results from eHealth API, try individual words in eHealth API then filter by all terms
        $words = array_values(array_filter(
            preg_split('/\s+/u', mb_strtolower($query)) ?: [],
            static fn (string $w): bool => mb_strlen($w) >= 3
        ));

        if (count($words) > 1) {
            // Sort by word length descending so we query the most specific keyword first
            usort($words, static fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));

            $collected = [];
            foreach ($words as $word) {
                try {
                    $raw = $fetch($base + ['name' => $word]);
                    $flattened = self::flattenRequestable($raw);
                    foreach ($flattened as $svc) {
                        if (!empty($svc['id'])) {
                            $collected[$svc['id']] = $svc;
                        }
                    }
                } catch (\Throwable) {
                }

                $filtered = self::filterByQueryNeedle(array_values($collected), $query);
                if ($filtered !== []) {
                    return $filtered;
                }
            }
        }

        return [];
    }

    /**
     * Service-request category from an eHealth catalog node (same codes as
     * eHealth/SNOMED/service_request_categories).
     *
     * @param  array<string, mixed>  $service
     */
    public static function requestCategory(array $service): ?string
    {
        $category = $service['category'] ?? null;
        if (!is_string($category) || trim($category) === '') {
            return null;
        }

        return strtolower(trim($category));
    }

    public static function isCodeQuery(string $query): bool
    {
        return (bool) preg_match('/^[\p{L}0-9\-\.]+$/u', $query)
            && (bool) preg_match('/[0-9]/', $query)
            && !str_contains($query, ' ');
    }

    /**
     * @return list<string>
     */
    public static function codeCandidates(string $query): array
    {
        $candidates = [$query];
        if (preg_match('/^\d+$/', $query) === 1) {
            $candidates[] = $query.'-00';
        }

        return array_values(array_unique($candidates));
    }

    /**
     * @param  list<array<string, mixed>>  $nodes
     * @return list<array<string, mixed>>
     */
    public static function flattenRequestable(array $nodes): array
    {
        $services = [];
        self::collectRequestable($nodes, $services);

        return array_values($services);
    }

    /**
     * Filter services so only those whose name OR code matches all search terms in the query are returned.
     *
     * @param  list<array<string, mixed>>  $services
     * @return list<array<string, mixed>>
     */
    public static function filterByQueryNeedle(array $services, string $query): array
    {
        $cleanQuery = mb_strtolower(trim($query));
        if ($cleanQuery === '') {
            return [];
        }

        $terms = array_values(array_filter(
            preg_split('/\s+/u', $cleanQuery) ?: [],
            static fn (string $t): bool => $t !== ''
        ));

        return array_values(array_filter($services, static function (array $service) use ($cleanQuery, $terms): bool {
            $name = mb_strtolower((string) ($service['name'] ?? ''));
            $code = mb_strtolower((string) ($service['code'] ?? ''));

            // Exact match / substring match of the full query
            if (str_contains($name, $cleanQuery) || str_contains($code, $cleanQuery)) {
                return true;
            }

            // If multi-term, verify every term appears in name or code
            if (count($terms) > 1) {
                foreach ($terms as $term) {
                    if (!str_contains($name, $term) && !str_contains($code, $term)) {
                        return false;
                    }
                }

                return true;
            }

            return false;
        }));
    }

    /**
     * @param  list<array<string, mixed>>  $nodes
     * @param  array<string, array<string, mixed>>  $services
     */
    private static function collectRequestable(array $nodes, array &$services): void
    {
        foreach ($nodes as $node) {
            if (!is_array($node) || empty($node['id'])) {
                continue;
            }

            $isInactive = isset($node['is_active']) && $node['is_active'] === false;
            $hasCode = !empty($node['code']);
            $isContainer = !empty($node['groups']) || !empty($node['services']);
            $requestAllowed = (bool) ($node['request_allowed'] ?? false);

            if (!$isInactive && $hasCode && ($requestAllowed || !$isContainer)) {
                $services[$node['id']] = $node;
            }

            if (!empty($node['services']) && is_array($node['services'])) {
                foreach ($node['services'] as $service) {
                    if (!is_array($service) || empty($service['id'])) {
                        continue;
                    }
                    if (isset($service['is_active']) && $service['is_active'] === false) {
                        continue;
                    }
                    $services[$service['id']] = $service;
                }
            }

            if (!empty($node['groups']) && is_array($node['groups'])) {
                self::collectRequestable($node['groups'], $services);
            }
        }
    }
}
