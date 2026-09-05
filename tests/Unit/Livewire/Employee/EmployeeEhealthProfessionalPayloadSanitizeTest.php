<?php

declare(strict_types=1);

namespace Tests\Unit\Livewire\Employee;

use App\Livewire\Employee\EmployeeCreate;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Tests\TestCase;

class EmployeeEhealthProfessionalPayloadSanitizeTest extends TestCase
{
    #[Test]
    public function speciality_payload_drops_camel_case_aliases(): void
    {
        $method = new ReflectionMethod(EmployeeCreate::class, 'sanitizeSpecialityForEhealth');
        $method->setAccessible(true);

        $sanitized = $method->invoke(new EmployeeCreate(), [
            'speciality' => 'FAMILY_DOCTOR',
            'specialityOfficio' => true,
            'speciality_officio' => true,
            'attestationDate' => '2020-01-15',
            'attestation_date' => '2020-01-15',
            'attestationName' => 'МОЗ',
            'certificateNumber' => '123',
            'qualificationType' => 'AWARDING',
            'level' => 'FIRST',
            'extra' => 'nope',
        ]);

        $this->assertSame('FAMILY_DOCTOR', $sanitized['speciality']);
        $this->assertTrue($sanitized['speciality_officio']);
        $this->assertSame('FIRST', $sanitized['level']);
        $this->assertSame('AWARDING', $sanitized['qualification_type']);
        $this->assertSame('МОЗ', $sanitized['attestation_name']);
        $this->assertSame('2020-01-15', $sanitized['attestation_date']);
        $this->assertSame('123', $sanitized['certificate_number']);
        $this->assertArrayNotHasKey('attestationDate', $sanitized);
        $this->assertArrayNotHasKey('specialityOfficio', $sanitized);
        $this->assertArrayNotHasKey('extra', $sanitized);
    }

    #[Test]
    public function qualification_payload_keeps_optional_tv_fields_in_snake_case(): void
    {
        $method = new ReflectionMethod(EmployeeCreate::class, 'sanitizeQualificationForEhealth');
        $method->setAccessible(true);

        $sanitized = $method->invoke(new EmployeeCreate(), [
            'type' => 'INTERNSHIP',
            'institutionName' => 'НМУ',
            'speciality' => 'FAMILY_DOCTOR',
            'issuedDate' => '2026-08-01',
            'certificateNumber' => '312321',
            'validTo' => '2027-03-04',
            'additionalInfo' => 'note',
        ]);

        $this->assertSame([
            'type' => 'INTERNSHIP',
            'institution_name' => 'НМУ',
            'speciality' => 'FAMILY_DOCTOR',
            'issued_date' => '2026-08-01',
            'certificate_number' => '312321',
            'valid_to' => '2027-03-04',
            'additional_info' => 'note',
        ], $sanitized);
    }

    #[Test]
    public function employee_edit_primary_speciality_block_uses_only_snake_case_keys(): void
    {
        $source = file_get_contents(app_path('Livewire/Employee/EmployeeEdit.php'));

        $this->assertNotFalse($source);
        $this->assertStringContainsString("'speciality_officio' => true", $source);
        $this->assertStringContainsString("'attestation_date' => \$attestationDate", $source);
        $this->assertStringNotContainsString("'specialityOfficio' => true", $source);
        $this->assertStringNotContainsString("'attestationDate' => \$attestationDate", $source);
        $this->assertStringNotContainsString("'certificateNumber' =>", $source);
    }
}
