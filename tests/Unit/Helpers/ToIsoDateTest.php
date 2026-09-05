<?php

declare(strict_types=1);

namespace Tests\Unit\Helpers;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ToIsoDateTest extends TestCase
{
    #[Test]
    public function it_normalizes_app_date_format_to_iso(): void
    {
        $this->assertSame('2026-08-24', toIsoDate('24.08.2026'));
        $this->assertSame('2026-08-24', toIsoDate('2026-08-24'));
        $this->assertNull(toIsoDate(null));
        $this->assertNull(toIsoDate(''));
    }

    #[Test]
    public function stopped_range_compare_works_after_normalization(): void
    {
        $start = toIsoDate('01.08.2026');
        $end = toIsoDate('24.08.2026');
        $today = '2026-08-24';

        $this->assertSame('2026-08-01', $start);
        $this->assertTrue($end >= $start);
        $this->assertTrue($end <= $today);
        $this->assertFalse('24.08.2026' < '2026-08-01');
    }
}
