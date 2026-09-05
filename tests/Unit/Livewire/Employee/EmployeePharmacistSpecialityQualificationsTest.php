<?php

declare(strict_types=1);

namespace Tests\Unit\Livewire\Employee;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EmployeePharmacistSpecialityQualificationsTest extends TestCase
{
    #[Test]
    public function pharmacist_has_awarding_and_defense_speciality_qualification_types(): void
    {
        $allowed = config('ehealth.employee_type.PHARMACIST.speciality_qualification_type');

        $this->assertSame(['AWARDING', 'DEFENSE'], $allowed);
    }

    #[Test]
    public function medical_employee_types_expose_speciality_qualification_types(): void
    {
        foreach (config('ehealth.medical_employees') as $employeeType) {
            $allowed = config("ehealth.employee_type.{$employeeType}.speciality_qualification_type", []);

            $this->assertNotEmpty(
                $allowed,
                "{$employeeType} is missing speciality_qualification_type for the speciality modal."
            );
            $this->assertContains('AWARDING', $allowed);
            $this->assertContains('DEFENSE', $allowed);
        }
    }

    #[Test]
    public function pharmacist_spec_qualification_filter_keeps_dictionary_labels(): void
    {
        $allowed = config('ehealth.employee_type.PHARMACIST.speciality_qualification_type', []);
        $master = [
            'AWARDING' => 'Присвоєння',
            'DEFENSE' => 'Підтвердження',
            'INTERNSHIP' => 'Інтернатура',
        ];

        $filtered = array_intersect_key($master, array_flip($allowed));

        $this->assertSame([
            'AWARDING' => 'Присвоєння',
            'DEFENSE' => 'Підтвердження',
        ], $filtered);
    }

    #[Test]
    public function specialities_blade_binds_spec_qualification_options_by_employee_type(): void
    {
        $blade = file_get_contents(
            resource_path('views/livewire/employee/parts/specialities.blade.php')
        );

        $this->assertNotFalse($blade);
        $this->assertStringContainsString(
            "employeeTypeSpecQualifications: @js(\$this->employeeTypeSpecQualifications)",
            $blade
        );
        $this->assertStringContainsString(
            'employeeTypeSpecQualifications[employeeType]',
            $blade
        );
    }
}
