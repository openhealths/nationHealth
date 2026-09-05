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
}
