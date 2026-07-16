<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Employee identity documents that eHealth expects as a single "number"
 * but UI should collect as series + number (3.23.1.3.2).
 */
final class EmployeeDocumentSeriesNumber
{
    /**
     * Document types with fixed Cyrillic series (2 letters) + numeric part.
     *
     * @return list<string>
     */
    public static function typesRequiringSeries(): array
    {
        return [
            'PASSPORT',
            'REFUGEE_CERTIFICATE',
            'COMPLEMENTARY_PROTECTION_CERTIFICATE',
        ];
    }

    public static function requiresSeries(?string $type): bool
    {
        return $type !== null && in_array($type, self::typesRequiringSeries(), true);
    }

    /**
     * @return array{series: string, number: string}
     */
    public static function split(?string $type, ?string $combined): array
    {
        $combined = trim((string) $combined);

        if ($combined === '' || !self::requiresSeries($type)) {
            return ['series' => '', 'number' => $combined];
        }

        if (preg_match('/^(.{2})([0-9]+)$/u', $combined, $matches) === 1) {
            return [
                'series' => $matches[1],
                'number' => $matches[2],
            ];
        }

        return ['series' => '', 'number' => $combined];
    }

    public static function combine(?string $type, ?string $series, ?string $number): string
    {
        $series = trim((string) $series);
        $number = trim((string) $number);

        if (!self::requiresSeries($type)) {
            return $number;
        }

        return $series . $number;
    }

    /**
     * Normalize a document array for validation / eHealth (single number, no series key).
     *
     * @param  array<string, mixed>  $document
     * @return array<string, mixed>
     */
    public static function normalizeForApi(array $document): array
    {
        $type = $document['type'] ?? null;
        $series = $document['series'] ?? '';
        $number = (string) ($document['number'] ?? '');

        $document['number'] = self::combine(is_string($type) ? $type : null, is_string($series) ? $series : null, $number);
        unset($document['series']);

        return $document;
    }
}
