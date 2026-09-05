<?php

declare(strict_types=1);

namespace Tests\Unit\Services\MedicalEvents;

use App\Services\MedicalEvents\ReferralRequestLifecycleService;
use Tests\TestCase;

class ReferralPrintoutCode128Test extends TestCase
{
    public function test_build_code128_barcode_html_contains_image_and_requisition(): void
    {
        $service = app(ReferralRequestLifecycleService::class);
        $html = $service->buildCode128BarcodeHtml('AB12-CD34-EF56-GH78');

        $this->assertStringContainsString('data:image/png;base64,', $html);
        $this->assertStringContainsString('AB12-CD34-EF56-GH78', $html);
        $this->assertStringContainsString('CODE128', $html);
    }

    public function test_empty_requisition_returns_empty_html(): void
    {
        $service = app(ReferralRequestLifecycleService::class);

        $this->assertSame('', $service->buildCode128BarcodeHtml('   '));
    }
}
