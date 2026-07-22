<?php

declare(strict_types=1);

namespace Tests\Unit\Livewire\Employee;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EmployeeSpecialityPrimaryAddGuardTest extends TestCase
{
    #[Test]
    public function specialities_blade_guards_primary_on_add(): void
    {
        $blade = file_get_contents(resource_path('views/livewire/employee/parts/specialities.blade.php'));

        $this->assertNotFalse($blade);
        $this->assertStringContainsString('openAddSpecialityModal()', $blade);
        $this->assertStringContainsString('canMarkAsPrimary()', $blade);
        $this->assertStringContainsString('saveSpeciality()', $blade);
        $this->assertStringContainsString('multiple_primary_specialities', $blade);
        // Position-add locks personal data but must keep specialities editable (same as education).
        $this->assertStringContainsString(':disabled="$wire.isPositionDataLocked ?? false"', $blade);
        $this->assertStringNotContainsString('isPersonalDataLocked', $blade);
    }
}
