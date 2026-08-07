<?php

declare(strict_types=1);

namespace Tests\Unit\Livewire\Employee;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EmployeeSpecialityFieldsetLockTest extends TestCase
{
    #[Test]
    public function specialities_fieldset_locks_with_position_not_personal_data(): void
    {
        $blade = file_get_contents(resource_path('views/livewire/employee/parts/specialities.blade.php'));

        $this->assertNotFalse($blade);
        $this->assertStringContainsString(':disabled="$wire.isPositionDataLocked ?? false"', $blade);
        $this->assertStringNotContainsString('isPersonalDataLocked', $blade);
    }
}
