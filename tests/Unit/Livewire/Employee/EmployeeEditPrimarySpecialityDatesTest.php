<?php

declare(strict_types=1);

namespace Tests\Unit\Livewire\Employee;

use App\Casts\EHealthDateCast;
use App\Models\Relations\Speciality;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EmployeeEditPrimarySpecialityDatesTest extends TestCase
{
    #[Test]
    public function employee_edit_normalizes_cast_dates_via_to_iso_date(): void
    {
        $source = file_get_contents(app_path('Livewire/Employee/EmployeeEdit.php'));

        $this->assertNotFalse($source);
        $this->assertStringContainsString('toIsoDate($originalPrimarySpeciality->attestationDate)', $source);
        $this->assertStringContainsString('toIsoDate($originalPrimarySpeciality->validToDate)', $source);
        $this->assertDoesNotMatchRegularExpression('/\$attestationDate\?->format\(/', $source);
        $this->assertDoesNotMatchRegularExpression('/\$validToDate->format\(/', $source);
        $this->assertDoesNotMatchRegularExpression('/attestation_date\?->format\(/', $source);
        $this->assertDoesNotMatchRegularExpression('/valid_to_date->format\(/', $source);
    }

    #[Test]
    public function ehealth_date_cast_returns_formatted_string_not_carbon(): void
    {
        $model = new Speciality();
        $cast = new EHealthDateCast();

        $value = $cast->get($model, 'attestation_date', '2020-01-15', []);

        $this->assertIsString($value);
        $this->assertSame('2020-01-15', toIsoDate($value));
    }
}
