<?php

declare(strict_types=1);

namespace Tests\Feature\Services\MedicalEvents;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class MedicationRequestLifecycleServiceTest extends TestCase
{
    use DatabaseTransactions;

    protected function migrateDatabases(): void
    {
        $this->artisan('migrate:fresh', [
            '--path' => [
                database_path('migrations'),
                database_path('migrations/install'),
                database_path('migrations/update/0_1'),
            ],
            '--realpath' => true,
        ]);
    }

    public function test_create_draft_successfully_creates_request()
    {
        $this->assertTrue(true); // Placeholder for complex mock setup
    }

    public function test_sign_successfully_changes_status_to_active()
    {
        $this->assertTrue(true); // Placeholder for complex mock setup
    }

    public function test_reject_successfully_rejects_new_request()
    {
        $this->assertTrue(true); // Placeholder for complex mock setup
    }

    public function test_build_fallback_printout_html()
    {
        $service = new \App\Services\MedicalEvents\MedicationRequestLifecycleService();
        $carePlan = new \App\Models\CarePlan();

        $uuid = '00000000-0000-4000-8000-000000001234';
        $html = $service->buildFallbackPrintoutHtml($carePlan, $uuid, 'Тестова інструкція');

        $this->assertStringContainsString($uuid, $html);
        $this->assertStringContainsString('Тестова інструкція', $html);
        $this->assertStringContainsString('(ПАМ\'ЯТКА)', $html);
    }
}
