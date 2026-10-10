<?php

declare(strict_types=1);

namespace Tests\Unit\Livewire\Employee;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EmployeePositionAddEmailInputTest extends TestCase
{
    #[Test]
    public function position_partial_allows_typing_new_email_via_datalist_input(): void
    {
        $blade = file_get_contents(resource_path('views/livewire/employee/parts/position.blade.php'));

        $this->assertNotFalse($blade);
        $this->assertStringContainsString('list="party-user-emails"', $blade);
        $this->assertStringContainsString('type="email"', $blade);
        $this->assertStringContainsString('wire:model="formEmail"', $blade);
        $this->assertStringNotContainsString('<select name="formEmail"', $blade);
        $this->assertStringContainsString('partyUsers !== null', $blade);
    }
}
