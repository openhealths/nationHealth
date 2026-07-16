<?php

declare(strict_types=1);

namespace Tests\Unit\Livewire\Employee;

use App\Livewire\Employee\Forms\EmployeeForm;
use Illuminate\Support\Facades\Validator;
use Livewire\Component;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Tests\TestCase;

class EmployeePrimarySpecialityValidationTest extends TestCase
{
    #[Test]
    public function rejects_more_than_one_primary_speciality(): void
    {
        $component = new class extends Component
        {
            public array $dictionaries = [
                'GENDER' => ['MALE' => 'Чоловік'],
                'PHONE_TYPE' => ['MOBILE' => 'Мобільний'],
                'POSITION' => ['P1' => 'Лікар'],
            ];

            public function render()
            {
                return '';
            }
        };

        $form = new EmployeeForm($component, 'form');
        $form->employeeType = 'DOCTOR';

        $method = new ReflectionMethod(EmployeeForm::class, 'doctorRules');
        $rules = $method->invoke($form);

        $validator = Validator::make(
            [
                'doctor' => [
                    'specialities' => [
                        [
                            'speciality' => 'FAMILY_DOCTOR',
                            'specialityOfficio' => true,
                        ],
                        [
                            'speciality' => 'THERAPIST',
                            'specialityOfficio' => true,
                        ],
                    ],
                ],
            ],
            [
                'doctor.specialities' => $rules['doctor.specialities'],
            ]
        );

        $this->assertTrue($validator->fails());
        $this->assertStringContainsString(
            'більше однієї спеціальності',
            $validator->errors()->first('doctor.specialities')
        );
    }
}
