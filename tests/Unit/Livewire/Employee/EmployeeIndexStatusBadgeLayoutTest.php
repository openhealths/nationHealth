<?php

declare(strict_types=1);

namespace Tests\Unit\Livewire\Employee;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EmployeeIndexStatusBadgeLayoutTest extends TestCase
{
    #[Test]
    public function status_badges_hug_text_width_when_wrapping(): void
    {
        $blade = file_get_contents(resource_path('views/livewire/employee/employee-index.blade.php'));

        $this->assertNotFalse($blade);
        $this->assertStringContainsString('w-[10%]">{{ __(\'forms.status.label\') }}', $blade);
        $this->assertStringContainsString('inline-block w-min whitespace-normal text-left leading-tight', $blade);
        $this->assertStringNotContainsString('max-w-[6.75rem]', $blade);
        $this->assertStringNotContainsString('[&_span]:text-center', $blade);
        $this->assertStringNotContainsString('[&_span]:max-w-full', $blade);
        $this->assertStringContainsString('shrink-0 whitespace-nowrap text-center align-middle', $blade);
    }

    #[Test]
    public function status_filter_includes_stopped_and_entered_in_error(): void
    {
        $blade = file_get_contents(resource_path('views/livewire/employee/employee-index.blade.php'));

        $this->assertNotFalse($blade);
        $this->assertStringContainsString('Status::STOPPED->value', $blade);
        $this->assertStringContainsString('Status::ENTERED_IN_ERROR->value', $blade);
        $this->assertStringContainsString("__('forms.status.stopped')", $blade);
    }

    #[Test]
    public function deactivate_modal_exposes_status_and_end_date_fields(): void
    {
        $blade = file_get_contents(resource_path('views/livewire/employee/parts/modals/deactivate-modal.blade.php'));

        $this->assertNotFalse($blade);
        $this->assertStringContainsString('wire:model.live="deactivationStatus"', $blade);
        $this->assertStringContainsString('wire:model="deactivationEndDate"', $blade);
        $this->assertStringContainsString('ENTERED_IN_ERROR', $blade);
        $this->assertStringContainsString('STOPPED', $blade);
    }
}
