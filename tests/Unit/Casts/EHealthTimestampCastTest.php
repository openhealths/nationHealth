<?php

declare(strict_types=1);

namespace Tests\Unit\Casts;

use App\Casts\EHealthTimestampCast;
use App\Models\MedicalEvents\Sql\Period;
use Carbon\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class EHealthTimestampCastTest extends TestCase
{
    #[DataProvider('timestamps')]
    public function testStoresUtcAndPreservesLocalDisplay(string $timezone, string $input, string $stored, string $display): void
    {
        config(['app.timezone' => $timezone]);
        $cast = new EHealthTimestampCast();
        $period = new Period();

        $actual = $cast->set($period, 'end', $input, []);

        $this->assertSame($stored, $actual);
        $this->assertSame($display, $cast->get($period, 'end', $actual, []));
    }

    public static function timestamps(): array
    {
        return [
            'UTC timestamp' => ['UTC', '2026-10-24T18:37:24Z', '2026-10-24 18:37:24', '24.10.2026 18:37'],
            'summer offset' => ['Europe/Kyiv', '2026-08-13T23:30:00+03:00', '2026-08-13 20:30:00', '13.08.2026 23:30'],
            'winter offset' => ['Europe/Kyiv', '2026-01-15T23:30:00+02:00', '2026-01-15 21:30:00', '15.01.2026 23:30'],
            'before autumn transition' => ['Europe/Kyiv', '2026-10-24T21:37:24+03:00', '2026-10-24 18:37:24', '24.10.2026 21:37'],
            'after autumn transition' => ['Europe/Kyiv', '2026-10-25T21:37:24+02:00', '2026-10-25 19:37:24', '25.10.2026 21:37'],
            'first repeated hour' => ['Europe/Kyiv', '2026-10-25T03:30:00+03:00', '2026-10-25 00:30:00', '25.10.2026 03:30'],
            'second repeated hour' => ['Europe/Kyiv', '2026-10-25T03:30:00+02:00', '2026-10-25 01:30:00', '25.10.2026 03:30'],
            'local midnight' => ['Europe/Kyiv', '2026-08-13T00:15:00+03:00', '2026-08-12 21:15:00', '13.08.2026 00:15'],
            'negative offset' => ['UTC', '2026-10-25T00:15:00-04:00', '2026-10-25 04:15:00', '25.10.2026 04:15'],
            'local display value' => ['Europe/Kyiv', '24.10.2026 21:37', '2026-10-24 18:37:00', '24.10.2026 21:37'],
            'naive UTC input' => ['UTC', '2026-10-24 18:37:24', '2026-10-24 18:37:24', '24.10.2026 18:37'],
        ];
    }

    public function testDoesNotMutateTheInputDate(): void
    {
        $input = Carbon::parse('2026-10-24T21:37:24+03:00');
        $cast = new EHealthTimestampCast();

        $this->assertSame('2026-10-24 18:37:24', $cast->set(new Period(), 'end', $input, []));
        $this->assertSame('2026-10-24T21:37:24+03:00', $input->toIso8601String());
    }

    #[DataProvider('emptyValues')]
    public function testEmptyValuesRemainNull(?string $value): void
    {
        $cast = new EHealthTimestampCast();
        $period = new Period();

        $this->assertNull($cast->set($period, 'end', $value, []));
        $this->assertNull($cast->get($period, 'end', $value, []));
    }

    public static function emptyValues(): array
    {
        return ['null' => [null], 'empty string' => ['']];
    }
}
