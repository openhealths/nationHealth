<?php

declare(strict_types=1);

namespace Tests\Unit\Livewire\EmployeeRole;

use App\Livewire\EmployeeRole\EmployeeRoleCreate;
use App\Models\Division;
use App\Models\HealthcareService;
use Tests\TestCase;

class EmployeeRoleCreateLabelTest extends TestCase
{
    public function test_healthcare_service_option_label_uses_division_name_when_speciality_is_empty(): void
    {
        $component = new class extends EmployeeRoleCreate
        {
            public function exposeLabel(HealthcareService $service): string
            {
                $this->dictionaries = ['SPECIALITY_TYPE' => ['THERAPY' => 'Терапія']];

                return $this->healthcareServiceOptionLabel($service);
            }
        };

        $service = new HealthcareService([
            'speciality_type' => '',
        ]);
        $service->setRelation('division', new Division(['name' => 'Амбулаторія']));

        $this->assertSame('Амбулаторія', $component->exposeLabel($service));
    }

    public function test_healthcare_service_option_label_includes_speciality_and_division(): void
    {
        $component = new class extends EmployeeRoleCreate
        {
            public function exposeLabel(HealthcareService $service): string
            {
                $this->dictionaries = ['SPECIALITY_TYPE' => ['THERAPY' => 'Терапія']];

                return $this->healthcareServiceOptionLabel($service);
            }
        };

        $service = new HealthcareService([
            'speciality_type' => 'THERAPY',
        ]);
        $service->setRelation('division', new Division(['name' => 'Амбулаторія']));

        $this->assertSame('Терапія - Амбулаторія', $component->exposeLabel($service));
    }
}
