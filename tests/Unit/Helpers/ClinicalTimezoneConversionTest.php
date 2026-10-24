<?php

declare(strict_types=1);

namespace Tests\Unit\Helpers;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ClinicalTimezoneConversionTest extends TestCase
{
    #[DataProvider('displayTimezones')]
    public function test_timestamp_conversion_uses_configured_display_zone_and_canonical_utc(
        string $timezone,
        string $localTime,
        string $utcTime
    ): void {
        config(['app.timezone' => $timezone]);

        $this->assertSame($utcTime, convertToEHealthISO8601($localTime));
        $this->assertSame($localTime, convertToLocalTimezone($utcTime));
        $this->assertSame($localTime, convertToLocalTimezone(str_replace(['T', 'Z'], [' ', ''], $utcTime)));
        $this->assertSame('2026-10-24', toIsoDate('2026-10-24'));
    }

    public function test_explicit_offsets_identify_the_same_instant_in_every_display_zone(): void
    {
        foreach (['Europe/Kyiv', 'Europe/Berlin', 'America/New_York', 'UTC'] as $timezone) {
            config(['app.timezone' => $timezone]);

            $this->assertSame('2026-10-23T21:42:00Z', convertToEHealthISO8601('2026-10-24T00:42:00+03:00'));
            $this->assertSame('2026-10-23T21:42:00Z', convertToEHealthISO8601('2026-10-23T21:42:00Z'));
        }
    }

    public static function displayTimezones(): array
    {
        return [
            'Kyiv' => ['Europe/Kyiv', '2026-10-24 00:42:00', '2026-10-23T21:42:00Z'],
            'Berlin' => ['Europe/Berlin', '2026-10-24 00:42:00', '2026-10-23T22:42:00Z'],
            'New York' => ['America/New_York', '2026-10-24 00:42:00', '2026-10-24T04:42:00Z'],
            'UTC' => ['UTC', '2026-10-24 00:42:00', '2026-10-24T00:42:00Z'],
        ];
    }
}
