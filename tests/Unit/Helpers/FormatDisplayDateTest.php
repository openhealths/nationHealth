<?php

declare(strict_types=1);

namespace Tests\Unit\Helpers;

use Carbon\CarbonImmutable;
use Tests\TestCase;

class FormatDisplayDateTest extends TestCase
{
    public function test_format_display_date_accepts_carbon_instance(): void
    {
        $formatted = formatDisplayDate(CarbonImmutable::parse('2026-06-18'));

        $this->assertSame('18.06.2026', $formatted);
    }

    public function test_format_display_date_accepts_iso_string(): void
    {
        $formatted = formatDisplayDate('2026-06-18');

        $this->assertSame('18.06.2026', $formatted);
    }

    public function test_format_display_date_returns_empty_string_for_null(): void
    {
        $this->assertSame('', formatDisplayDate(null));
    }

    public function test_format_display_date_time_includes_hours(): void
    {
        $formatted = formatDisplayDateTime('2026-06-17T10:19:00Z');

        $this->assertStringContainsString('17.06.2026', $formatted);
        $this->assertStringContainsString('10:19', $formatted);
    }
}
