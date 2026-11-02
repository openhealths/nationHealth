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
    use \Tests\Concerns\FakesEmployeeDictionaries;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeEmployeeDictionaries();
    }

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

    #[Test]
    public function rejects_zero_primary_specialities_for_medical_type(): void
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
                            'specialityOfficio' => false,
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
            'рівно одну спеціальність',
            $validator->errors()->first('doctor.specialities')
        );
    }

    #[Test]
    public function allows_active_cross_role_additional_speciality_but_rejects_it_as_primary(): void
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

        $rules = (new ReflectionMethod(EmployeeForm::class, 'doctorRules'))->invoke($form);
        $specialityRules = array_filter(
            $rules,
            static fn (string $key): bool => str_starts_with($key, 'doctor.specialities'),
            ARRAY_FILTER_USE_KEY
        );

        $validPayload = [
            'doctor' => [
                'specialities' => [
                    [
                        'speciality' => 'THERAPIST',
                        'specialityOfficio' => true,
                        'level' => 'SECOND',
                        'qualificationType' => 'AWARDING',
                        'attestationName' => 'МОЗ',
                        'attestationDate' => '2024-01-01',
                        'validToDate' => null,
                        'certificateNumber' => 'DOC-001',
                    ],
                    [
                        'speciality' => 'NURSING',
                        'specialityOfficio' => false,
                        'level' => 'SECOND',
                        'qualificationType' => 'AWARDING',
                        'attestationName' => 'МОЗ',
                        'attestationDate' => '2023-05-10',
                        'validToDate' => null,
                        'certificateNumber' => 'NURSE-002',
                    ],
                ],
            ],
        ];

        $this->assertTrue(Validator::make($validPayload, $specialityRules, [], $form->validationAttributes())->passes());

        $invalidPrimaryPayload = $validPayload;
        $invalidPrimaryPayload['doctor']['specialities'] = [
            array_replace($validPayload['doctor']['specialities'][1], ['specialityOfficio' => true]),
        ];

        $invalidValidator = Validator::make(
            $invalidPrimaryPayload,
            $specialityRules,
            [],
            $form->validationAttributes()
        );

        $this->assertTrue($invalidValidator->fails());
        $this->assertArrayHasKey('doctor.specialities.0.speciality', $invalidValidator->errors()->toArray());
    }

    #[Test]
    public function translates_doctor_specialities_validation_attributes_in_ukrainian(): void
    {
        app()->setLocale('uk');

        $component = new class extends Component
        {
            public array $dictionaries = [];

            public function render()
            {
                return '';
            }
        };

        $form = new EmployeeForm($component, 'form');
        $form->employeeType = 'DOCTOR';

        $rules = (new ReflectionMethod(EmployeeForm::class, 'doctorRules'))->invoke($form);
        $specialityRules = array_filter(
            $rules,
            static fn (string $key): bool => str_starts_with($key, 'doctor.specialities'),
            ARRAY_FILTER_USE_KEY
        );

        $validator = Validator::make(
            [
                'doctor' => [
                    'specialities' => [
                        [
                            'speciality' => '',
                            'specialityOfficio' => true,
                            'level' => 'SECOND',
                            'qualificationType' => 'AWARDING',
                            'attestationName' => 'МОЗ',
                            'attestationDate' => '2024-01-01',
                            'validToDate' => null,
                            'certificateNumber' => 'DOC-001',
                        ],
                    ],
                ],
            ],
            $specialityRules,
            [],
            $form->validationAttributes()
        );

        $this->assertTrue($validator->fails());
        $message = $validator->errors()->first('doctor.specialities.0.speciality');
        $this->assertStringNotContainsString('doctor.specialities.0.speciality', $message);
        $this->assertStringContainsString(__('forms.speciality'), $message);
    }
}
