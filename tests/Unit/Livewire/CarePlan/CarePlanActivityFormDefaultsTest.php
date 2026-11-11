<?php

declare(strict_types=1);

namespace Tests\Unit\Livewire\CarePlan;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CarePlanActivityFormDefaultsTest extends TestCase
{
    #[Test]
    public function daily_amount_validation_attribute_is_translated(): void
    {
        $this->assertSame(
            'Кількість за 1 раз',
            __('validation.attributes.activityForm.daily_amount')
        );
    }

    #[Test]
    public function activity_drawers_bind_expected_result_to_goal(): void
    {
        foreach ([
            'services-drawer.blade.php',
            'medication-form-drawer.blade.php',
            'medical-device-form-drawer.blade.php',
        ] as $file) {
            $blade = file_get_contents(resource_path('views/livewire/care-plan/parts/modals/'.$file));

            $this->assertNotFalse($blade);
            $this->assertStringContainsString('wire:model="activityForm.goal"', $blade);
            $this->assertStringContainsString('care_plan_activity_goals', $blade);
        }
    }

    #[Test]
    public function eprescription_program_card_uses_readable_dark_theme_classes(): void
    {
        $blade = file_get_contents(
            resource_path('views/livewire/care-plan/parts/modals/eprescription-form-drawer.blade.php')
        );

        $this->assertNotFalse($blade);
        $this->assertStringNotContainsString('dark:bg-gray-750', $blade);
        $this->assertStringContainsString('dark:bg-gray-700', $blade);
        $this->assertStringContainsString('dark:text-gray-300', $blade);
    }
}
