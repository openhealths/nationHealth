<?php

declare(strict_types=1);

namespace Tests\Unit\Livewire\Employee;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EmployeePositionAddBladeRootTest extends TestCase
{
    #[Test]
    public function position_add_blade_keeps_alpine_modal_bindings_on_root_div(): void
    {
        $blade = file_get_contents(resource_path('views/livewire/employee/employee-position-add.blade.php'));

        $this->assertNotFalse($blade);
        $this->assertMatchesRegularExpression(
            '/<div\s+x-data="\{\s*showSignatureModal:/s',
            $blade
        );
        $this->assertStringNotContainsString(
            ">
        x-data=\"{",
            $blade
        );
    }
}
