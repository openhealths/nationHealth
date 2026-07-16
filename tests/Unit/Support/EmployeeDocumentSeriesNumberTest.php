<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\EmployeeDocumentSeriesNumber;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EmployeeDocumentSeriesNumberTest extends TestCase
{
    #[Test]
    #[DataProvider('seriesTypesProvider')]
    public function requires_series_for_passport_like_types(string $type): void
    {
        $this->assertTrue(EmployeeDocumentSeriesNumber::requiresSeries($type));
    }

    public static function seriesTypesProvider(): array
    {
        return [
            ['PASSPORT'],
            ['REFUGEE_CERTIFICATE'],
            ['COMPLEMENTARY_PROTECTION_CERTIFICATE'],
        ];
    }

    #[Test]
    public function does_not_require_series_for_national_id(): void
    {
        $this->assertFalse(EmployeeDocumentSeriesNumber::requiresSeries('NATIONAL_ID'));
    }

    #[Test]
    public function splits_and_combines_passport_number(): void
    {
        $parts = EmployeeDocumentSeriesNumber::split('PASSPORT', 'АА123456');

        $this->assertSame('АА', $parts['series']);
        $this->assertSame('123456', $parts['number']);
        $this->assertSame(
            'АА123456',
            EmployeeDocumentSeriesNumber::combine('PASSPORT', $parts['series'], $parts['number'])
        );
    }

    #[Test]
    public function normalize_for_api_removes_series_key(): void
    {
        $normalized = EmployeeDocumentSeriesNumber::normalizeForApi([
            'type' => 'PASSPORT',
            'series' => 'АБ',
            'number' => '654321',
            'issuedBy' => 'ДМС',
        ]);

        $this->assertSame('АБ654321', $normalized['number']);
        $this->assertArrayNotHasKey('series', $normalized);
        $this->assertSame('ДМС', $normalized['issuedBy']);
    }
}
