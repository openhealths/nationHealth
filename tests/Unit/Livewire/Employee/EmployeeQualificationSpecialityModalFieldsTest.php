<?php

declare(strict_types=1);

namespace Tests\Unit\Livewire\Employee;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EmployeeQualificationSpecialityModalFieldsTest extends TestCase
{
    #[Test]
    public function qualifications_modal_exposes_tv_required_and_optional_fields(): void
    {
        $blade = file_get_contents(resource_path('views/livewire/employee/parts/qualifications.blade.php'));

        $this->assertNotFalse($blade);
        $this->assertStringContainsString('modalQualification.type', $blade);
        $this->assertStringContainsString('modalQualification.institutionName', $blade);
        $this->assertStringContainsString('modalQualification.speciality', $blade);
        $this->assertStringContainsString('modalQualification.issuedDate', $blade);
        $this->assertStringContainsString('modalQualification.certificateNumber', $blade);
        $this->assertStringContainsString('modalQualification.validTo', $blade);
        $this->assertStringContainsString('modalQualification.additionalInfo', $blade);
        $this->assertStringContainsString('validTo = \'\'', $blade);
        $this->assertStringContainsString('additionalInfo = \'\'', $blade);
    }

    #[Test]
    public function specialities_modal_exposes_valid_to_date(): void
    {
        $blade = file_get_contents(resource_path('views/livewire/employee/parts/specialities.blade.php'));

        $this->assertNotFalse($blade);
        $this->assertStringContainsString('modalSpeciality.validToDate', $blade);
        $this->assertStringContainsString('speciality.validToDate', $blade);
        $this->assertStringContainsString('validToDate = \'\'', $blade);
    }

    #[Test]
    public function qualification_model_fillable_covers_tv_fields(): void
    {
        $fillable = (new \App\Models\Relations\Qualification())->getFillable();

        $this->assertContains('type', $fillable);
        $this->assertContains('institution_name', $fillable);
        $this->assertContains('speciality', $fillable);
        $this->assertContains('issued_date', $fillable);
        $this->assertContains('certificate_number', $fillable);
        $this->assertContains('valid_to', $fillable);
        $this->assertContains('additional_info', $fillable);
    }
}
